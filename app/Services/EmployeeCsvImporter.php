<?php

namespace App\Services;

use App\Models\Admin;
use App\Models\Convenio;
use App\Models\ConvenioJobCategory;
use App\Models\Employee;
use App\Models\Territory;
use App\Support\TextNormalizer;
use Illuminate\Support\Facades\DB;

/**
 * CSV bulk upload for the employee directory (ADR-0004 — the bootstrap path for
 * ~1,500 employees). Reuses the registry-import discipline: header parsed by
 * NAME (not column letter), per-row validation, validate→report→apply, and a
 * per-row pass/fail report — a bad row is REPORTED, never silently dropped.
 *
 * Apply imports only the VALID rows, each in its OWN transaction together with
 * its employee_audit_log write (the row and its audit never diverge). It is NOT
 * whole-file-atomic on purpose: one bad row must not kill a 1,500-row bootstrap
 * (Q4). FK scope resolves into EXISTING vocabulary only (no creation; ADR-0011).
 */
class EmployeeCsvImporter
{
    private const REQUIRED_HEADERS = ['email', 'full_name', 'convenio_numero'];

    /** Validate-only (dry run): the full per-row report, writes nothing. */
    public function validate(array $rows): array
    {
        return $this->report($this->analyze($rows));
    }

    /** Apply: import the valid rows (each its own txn + audit); same report shape. */
    public function apply(array $rows, Admin $actor): array
    {
        $analysis = $this->analyze($rows);
        if (! ($analysis['ok'] ?? false)) {
            return $this->report($analysis);
        }

        $created = 0;
        $updated = 0;

        foreach ($analysis['rows'] as &$row) {
            if ($row['status'] !== 'pass') {
                continue; // reported as fail, never applied
            }

            $payload = $row['_payload'];
            DB::transaction(function () use ($payload, $actor, &$created, &$updated) {
                $audit = app(EmployeeAuditLogger::class);
                $existing = Employee::where('email', $payload['email'])->first();

                if ($existing === null) {
                    $employee = Employee::create($payload);
                    $audit->recordCreated($employee, $actor);
                    $created++;
                } else {
                    $before = $audit->snapshot($existing);
                    $existing->fill($payload);
                    $existing->save();
                    $audit->recordChanges($existing, $before, $actor);
                    $updated++;
                }
            });
        }
        unset($row);

        $analysis['created'] = $created;
        $analysis['updated'] = $updated;

        return $this->report($analysis);
    }

    /**
     * Parse + validate every row against the DB and the controlled vocabulary.
     * Returns ['ok'=>bool, 'error'?, 'rows'=>[...]] where each row carries its
     * status/action/errors and (on pass) a resolved `_payload`.
     */
    private function analyze(array $rows): array
    {
        if (count($rows) < 1) {
            return ['ok' => false, 'error' => 'El archivo está vacío.', 'rows' => []];
        }

        $header = array_map(fn ($h) => $this->headerKey((string) $h), $rows[0]);
        $col = array_flip($header);
        $missing = array_values(array_filter(self::REQUIRED_HEADERS, fn ($h) => ! isset($col[$h])));
        if ($missing !== []) {
            return [
                'ok' => false,
                'error' => 'Faltan columnas obligatorias: '.implode(', ', $missing).'. Encontradas: '.implode(', ', array_filter($header)),
                'rows' => [],
            ];
        }

        $get = function (array $row, string $key) use ($col): string {
            $idx = $col[$key] ?? null;

            return $idx !== null ? trim((string) ($row[$idx] ?? '')) : '';
        };

        $out = [];
        $seenEmails = [];
        foreach (array_slice($rows, 1, null, true) as $i => $row) {
            $rowNumber = $i + 1; // 1-based incl. header → human row number in the sheet
            // Skip a fully blank line silently (not a bad row, just spacing).
            if (trim(implode('', array_map('strval', $row))) === '') {
                continue;
            }

            $email = strtolower($get($row, 'email'));
            $errors = [];

            if ($email === '') {
                $errors[] = 'Falta el correo.';
            } elseif (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = "Correo no válido: \"{$email}\".";
            } elseif (isset($seenEmails[$email])) {
                $errors[] = "Correo duplicado en el archivo (fila {$seenEmails[$email]}).";
            }
            if ($email !== '') {
                $seenEmails[$email] = $rowNumber;
            }

            $fullName = $get($row, 'full_name');
            if ($fullName === '') {
                $errors[] = 'Falta el nombre completo.';
            }

            $convenioNumero = $get($row, 'convenio_numero');
            $convenio = $convenioNumero !== '' ? Convenio::where('numero', $convenioNumero)->first() : null;
            if ($convenioNumero === '') {
                $errors[] = 'Falta el número de convenio.';
            } elseif ($convenio === null) {
                $errors[] = "Convenio no encontrado: \"{$convenioNumero}\".";
            }

            // Territory: explicit code wins (an employee may sit in a territory that
            // differs from an Estatal convenio); blank → fall back to convenio's.
            $territoryCode = $get($row, 'territory_code');
            $territory = null;
            if ($territoryCode !== '') {
                $territory = Territory::where('code', $territoryCode)->first();
                if ($territory === null) {
                    $errors[] = "Territorio no encontrado: \"{$territoryCode}\".";
                }
            } elseif ($convenio !== null) {
                $territory = $convenio->territory_id !== null ? Territory::find($convenio->territory_id) : null;
                if ($territory === null) {
                    $errors[] = 'Sin territorio: indica territory_code (el convenio no tiene territorio).';
                }
            }

            // Job category: optional; if given, must resolve WITHIN the convenio.
            $jobCategoryName = $get($row, 'job_category');
            $jobCategory = null;
            if ($jobCategoryName !== '' && $convenio !== null) {
                $key = TextNormalizer::key($jobCategoryName);
                $jobCategory = ConvenioJobCategory::where('convenio_id', $convenio->id)->get()
                    ->first(fn (ConvenioJobCategory $c) => TextNormalizer::key($c->name) === $key);
                if ($jobCategory === null) {
                    $errors[] = "Categoría \"{$jobCategoryName}\" no existe en el convenio.";
                }
            }

            // Employment type: default full_time when blank; validate when present.
            $employmentRaw = strtolower($get($row, 'employment_type'));
            $employmentType = match ($employmentRaw) {
                '', 'full_time', 'completa', 'jornada completa' => 'full_time',
                'part_time', 'parcial', 'media jornada' => 'part_time',
                default => null,
            };
            if ($employmentType === null) {
                $errors[] = "Tipo de jornada no válido: \"{$employmentRaw}\" (full_time | part_time).";
            }

            $startDate = $get($row, 'start_date');
            if ($startDate !== '' && strtotime($startDate) === false) {
                $errors[] = "Fecha de alta no válida: \"{$startDate}\".";
            }

            $status = $errors === [] ? 'pass' : 'fail';
            $action = $status === 'pass'
                ? (Employee::where('email', $email)->exists() ? 'update' : 'create')
                : 'skip';

            $entry = [
                'row_number' => $rowNumber,
                'email' => $email,
                'full_name' => $fullName,
                'action' => $action,
                'status' => $status,
                'errors' => $errors,
            ];

            if ($status === 'pass') {
                $entry['_payload'] = [
                    'email' => $email,
                    'full_name' => $fullName,
                    'employee_external_id' => $get($row, 'employee_external_id') ?: null,
                    'convenio_id' => $convenio->id,
                    'job_category_id' => $jobCategory?->id,
                    'territory_id' => $territory->id,
                    'work_location' => $get($row, 'work_location') ?: null,
                    'employment_type' => $employmentType,
                    'start_date' => $startDate !== '' ? date('Y-m-d', (int) strtotime($startDate)) : null,
                    'status' => 'active',
                ];
            }

            $out[] = $entry;
        }

        return ['ok' => true, 'rows' => $out];
    }

    /** Strip the internal `_payload` and add the summary counters. */
    private function report(array $analysis): array
    {
        if (! ($analysis['ok'] ?? false)) {
            return [
                'ok' => false,
                'error' => $analysis['error'] ?? 'No se pudo leer el archivo.',
                'summary' => ['total' => 0, 'valid' => 0, 'invalid' => 0, 'created' => 0, 'updated' => 0],
                'rows' => [],
            ];
        }

        $rows = array_map(function (array $r) {
            unset($r['_payload']);

            return $r;
        }, $analysis['rows']);

        $valid = count(array_filter($rows, fn ($r) => $r['status'] === 'pass'));
        $invalid = count($rows) - $valid;

        return [
            'ok' => true,
            'summary' => [
                'total' => count($rows),
                'valid' => $valid,
                'invalid' => $invalid,
                'created' => $analysis['created'] ?? 0,
                'updated' => $analysis['updated'] ?? 0,
            ],
            'rows' => $rows,
        ];
    }

    /** Normalize a header cell to a lower_snake key (case/space/accent-insensitive). */
    private function headerKey(string $raw): string
    {
        // TextNormalizer::key returns UPPERCASE words separated by single spaces.
        $key = strtolower(TextNormalizer::key($raw));

        return str_replace(' ', '_', $key);
    }
}
