<?php

namespace App\Console\Commands;

use App\Models\SalaryTable;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Correction-salary-01, priority 3 — the read-only audit that made the
 * correction necessary, kept as a permanent guard.
 *
 * For every `salary_table_rows` row it compares the STORED
 * `base_salary_monthly` with any monthly figure the SOURCE itself stated (which
 * `raw_values` preserves verbatim, keyed by the normalized header cell) and
 * lists every disagreement with convenio / category / year.
 *
 * Three failure classes, each exiting non-zero — all of them are a STORED
 * figure that the source does not support:
 *  - `discrepancy` — a stored monthly that contradicts the source's own stated
 *    monthly. Under the old "canonical /14" rule this was every row of every
 *    convenio that does not pay in 14 (convenio 15: gazette 2.232,75 €, stored
 *    2.392,24 €).
 *  - `unsourced_monthly` / `unsourced_pagas_count` — a stored figure with NO
 *    stated counterpart anywhere in `raw_values`: i.e. a derived one, which
 *    ADR-0006 forbids. After the re-import there must be none, which is what
 *    makes this command a regression guard and not just a one-off script.
 *
 * And one COVERAGE class, reported but NOT a failure:
 *  - `stated_but_not_stored` — the source prints a monthly that no typed column
 *    holds. Sometimes that is a gap worth closing; sometimes it is the parser
 *    correctly refusing an ambiguous figure (a multi-year sheet that prints two
 *    "14 pagas" columns settles no year for either). Either way nothing wrong is
 *    STORED, so it is a thing to look at, not a thing to fail on.
 *
 * Writes nothing, ever. `--json` for machine use.
 */
class SalaryAuditMonthly extends Command
{
    protected $signature = 'salary:audit-monthly {--json : emit the audit as JSON}';

    protected $description = 'Read-only: compare every stored base_salary_monthly / pagas_count against what the source states in raw_values. Non-zero on any discrepancy or unsourced figure.';

    /**
     * Normalized header cells whose value IS a stated monthly base salary.
     * Mirrors hr-ai `salary.py`'s `_MONTHLY` — deliberately duplicated (not
     * imported) because hr-ai owns the parse and this command must be able to
     * audit rows hr-ai wrote at any earlier version. "bruto mes" is NOT here:
     * it is a gross monthly, a different concept from the base monthly column.
     */
    private const MONTHLY_KEYS = [
        'sb', 'salario base', 'salario base mensual', 'sueldo base', 'sueldo mensual',
        'salario mensual', 'base mensual', 'salario mes', 'salario/mes', 'base mes',
    ];

    /** Tolerance in euros — rounding between a sheet's float and a decimal(10,2) column. */
    private const TOLERANCE = 0.02;

    /** A finding about a STORED figure the source does not support → non-zero exit. */
    private const FAILING = ['discrepancy', 'unsourced_monthly', 'unsourced_pagas_count'];

    public function handle(): int
    {
        $findings = [];
        $rowsAudited = 0;
        $withStated = 0;

        $tables = SalaryTable::with(['rows.jobCategory', 'convenio:id,name', 'sourceDocument:id,source_filename'])
            ->orderBy('convenio_id')->orderBy('year')->get();

        foreach ($tables as $table) {
            foreach ($table->rows as $row) {
                $rowsAudited++;
                $rawValues = $row->raw_values ?? [];
                $stated = $this->statedMonthlies($rawValues);
                $storedMonthly = $row->base_salary_monthly === null ? null : (float) $row->base_salary_monthly;

                if ($stated !== []) {
                    $withStated++;
                }

                // A sheet may state several monthlies side by side (COEAS
                // Navarra prints "14 pagas" and "12 pagas"); the stored figure
                // is correct if it matches ANY of them, and the one it matches
                // is what gets reported.
                $match = null;
                foreach ($stated as $candidate) {
                    if ($storedMonthly !== null && abs($candidate['value'] - $storedMonthly) <= self::TOLERANCE) {
                        $match = $candidate;
                        break;
                    }
                }

                // Every applicable finding, not just the first — the wrong
                // monthly and the asserted-but-unstated pagas count are two
                // separate defects and both need counting.
                $rowFindings = [];
                if ($storedMonthly !== null && $stated !== [] && $match === null) {
                    $rowFindings[] = 'discrepancy';
                }
                if ($storedMonthly !== null && $stated === []) {
                    $rowFindings[] = 'unsourced_monthly';
                }
                if ($storedMonthly === null && $stated !== []) {
                    $rowFindings[] = 'stated_but_not_stored';
                }
                if ($row->pagas_count !== null && ! $this->statesPagas($rawValues, (int) $row->pagas_count)) {
                    $rowFindings[] = 'unsourced_pagas_count';
                }

                foreach ($rowFindings as $finding) {
                    $findings[] = [
                        'finding' => $finding,
                        'convenio_id' => $table->convenio_id,
                        'convenio' => $table->convenio->name ?? '?',
                        'year' => $table->year,
                        'table_id' => $table->id,
                        'source' => $table->source,
                        'document' => $table->sourceDocument->source_filename ?? '?',
                        'category' => $row->jobCategory->name ?? '?',
                        'stored_monthly' => $storedMonthly,
                        'stated_monthly' => $stated === [] ? null : $stated[0]['value'],
                        'stated_key' => $stated === [] ? null : implode(' / ', array_map(fn ($c) => $c['key'], $stated)),
                        'gross_annual' => $row->gross_annual === null ? null : (float) $row->gross_annual,
                        'pagas_count' => $row->pagas_count,
                        'implied_pagas' => $this->impliedPagas($row->gross_annual, $stated === [] ? null : $stated[0]['value']),
                    ];
                }
            }
        }

        $failing = array_values(array_filter($findings, fn ($f) => in_array($f['finding'], self::FAILING, true)));
        $coverage = array_values(array_filter($findings, fn ($f) => ! in_array($f['finding'], self::FAILING, true)));

        if ($this->option('json')) {
            $this->line(json_encode([
                'rows_audited' => $rowsAudited,
                'rows_with_a_stated_monthly' => $withStated,
                'failing' => count($failing),
                'findings' => $findings,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return $failing === [] ? self::SUCCESS : self::FAILURE;
        }

        $this->info("Audited {$rowsAudited} salary_table_rows across {$tables->count()} tables; {$withStated} carry a monthly figure the source itself states.");
        $this->newLine();

        // Coverage first and compactly: it is context for the failures, not a
        // list anyone needs row by row.
        if ($coverage !== []) {
            $byTable = [];
            foreach ($coverage as $f) {
                $key = "{$f['convenio_id']} ".mb_strimwidth($f['convenio'], 0, 30, '…')." · {$f['year']}";
                $byTable[$key] ??= ['rows' => 0, 'key' => $f['stated_key']];
                $byTable[$key]['rows']++;
            }
            $this->warn('The source states a monthly that no typed column holds — '.count($coverage).' row(s). Not a failure: nothing wrong is stored.');
            $this->table(['convenio · year', 'rows', 'stated by header'], array_map(
                fn ($k, $v) => [$k, $v['rows'], $v['key']],
                array_keys($byTable), array_values($byTable),
            ));
            $this->newLine();
        }

        if ($failing === []) {
            $this->info('No discrepancies: every stored monthly matches a source cell, and no stored figure is unsourced.');

            return self::SUCCESS;
        }

        $byFinding = [];
        foreach ($failing as $f) {
            $byFinding[$f['finding']][] = $f;
        }

        foreach ($byFinding as $kind => $rows) {
            $this->error(strtoupper(str_replace('_', ' ', $kind)).' — '.count($rows).' row(s):');
            $this->table(
                ['convenio', 'year', 'source', 'category', 'stored monthly', 'stated monthly', 'stated by header', 'gross annual', 'pagas', 'implied pagas'],
                array_map(fn ($f) => [
                    $f['convenio_id'].' '.mb_strimwidth($f['convenio'], 0, 28, '…'),
                    $f['year'] ?? '—',
                    $f['source'],
                    mb_strimwidth($f['category'], 0, 30, '…'),
                    $f['stored_monthly'] === null ? '—' : number_format($f['stored_monthly'], 2, ',', '.'),
                    $f['stated_monthly'] === null ? '—' : number_format($f['stated_monthly'], 2, ',', '.'),
                    $f['stated_key'] ?? '—',
                    $f['gross_annual'] === null ? '—' : number_format($f['gross_annual'], 2, ',', '.'),
                    $f['pagas_count'] ?? '—',
                    $f['implied_pagas'] ?? '—',
                ], $rows),
            );
            $this->newLine();
        }

        $this->error(count($failing).' stored figure(s) the source does not support — see above. Re-run `salary:import` for the affected documents (and `salary:pdf-to-xlsx --mark-provenance` for the OCR-derived ones) after fixing.');

        return self::FAILURE;
    }

    /**
     * Every monthly figure the source states: `raw_values` is keyed by the
     * NORMALIZED header cell hr-ai read, plus (for OCR-derived tables) the
     * verbatim original header text restored by `--mark-provenance`, so the
     * same figure can appear under two keys — deduplicated by value.
     *
     * @param  array<string,mixed>  $rawValues
     * @return list<array{key:string,value:float}>
     */
    private function statedMonthlies(array $rawValues): array
    {
        $found = [];
        foreach ($rawValues as $key => $value) {
            $normalized = $this->normalizeKey((string) $key);
            $isMonthly = in_array($normalized, self::MONTHLY_KEYS, true)
                || preg_match('/^\d{1,2}\s*pagas$/', $normalized) === 1;
            if (! $isMonthly) {
                continue;
            }
            $number = $this->toFloat($value);
            if ($number === null) {
                continue;
            }
            $seen = false;
            foreach ($found as $existing) {
                $seen = $seen || abs($existing['value'] - $number) <= self::TOLERANCE;
            }
            if (! $seen) {
                $found[] = ['key' => (string) $key, 'value' => $number];
            }
        }

        return $found;
    }

    /** Does any header in raw_values state exactly this pagas count? */
    private function statesPagas(array $rawValues, int $pagas): bool
    {
        foreach (array_keys($rawValues) as $key) {
            if (preg_match('/\b(\d{1,2})\s*pagas\b/', $this->normalizeKey((string) $key), $m) && (int) $m[1] === $pagas) {
                return true;
            }
        }

        return false;
    }

    /** annual ÷ stated monthly — diagnostic only, printed so a wrong divisor is obvious. */
    private function impliedPagas($gross, ?float $statedMonthly): ?string
    {
        if ($gross === null || $statedMonthly === null || $statedMonthly <= 0) {
            return null;
        }

        return number_format((float) $gross / $statedMonthly, 2, ',', '.');
    }

    /** Same normalization as hr-ai's `_norm()`: accents stripped, collapsed, lowercased. */
    private function normalizeKey(string $key): string
    {
        $s = str_replace("\n", ' ', $key);
        $s = Str::ascii($s);
        $s = preg_replace('/\s+/', ' ', $s);

        return trim(mb_strtolower(trim($s)), ' .:·-');
    }

    private function toFloat($value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        $s = trim($value);
        // Spanish decimal comma, as hr-ai's `_to_float()`.
        $s = str_contains($s, ',') && str_contains($s, '.')
            ? str_replace(',', '.', str_replace('.', '', $s))
            : str_replace(',', '.', $s);

        return is_numeric($s) ? (float) $s : null;
    }
}
