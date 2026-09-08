<?php

namespace App\Console\Commands;

use App\Models\Convenio;
use App\Models\ConvenioJobCategory;
use App\Models\Document;
use App\Models\SalaryTable;
use App\Models\SalaryTableRow;
use App\Services\ExtractionClient;
use App\Support\TextNormalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Deliberate, logged, idempotent salary import (ADR-0002/0014). hr-ai parses the
 * salary .xlsx and RETURNS rows (/extract-salary); THIS command (hr-backend)
 * writes salary_tables / salary_table_rows and populates convenio_job_categories.
 *
 * The admin running this command IS the deliberate action — the AI never mints
 * job categories at tag time. Categories are per-convenio (no global dedup).
 *
 * Every typed figure comes from a source cell (ADR-0006). Correction-salary-01
 * removed the old "14/12 canonical mapping" (base_salary_monthly =
 * gross_annual/14, num_payments = 14), which told a convenio that does not pay
 * in 14 a monthly figure its own gazette contradicted: `base_salary_monthly` is
 * now written only when the sheet has a monthly column, and `pagas_count` only
 * when a header states it. Every original column stays verbatim in raw_values.
 * hr-ai types the columns; this command persists them.
 *
 * FAILS LOUDLY (Correction-salary-01, priority 2): a document whose sheet was
 * recognized as a salary grid but yielded no rows, or whose header maps to no
 * typed field at all, is NOT written and the command exits non-zero without
 * printing a success line. Silently importing zero rows over a real grid is how
 * a coverage gap disguises itself as a completed import.
 *
 * Numero-less salary .xlsx land under_review at ingest and need an admin convenio
 * assignment first (ADR-0014, catch 4); this command imports only salary
 * documents that already have a resolved convenio, and lists the rest.
 */
class SalaryImport extends Command
{
    protected $signature = 'salary:import {--document= : import a single salary document by uuid}';

    protected $description = 'Extract salary .xlsx rows (via hr-ai) and write salary_tables/_rows + convenio_job_categories. Idempotent.';

    public function handle(ExtractionClient $client): int
    {
        // xlsx-first (ADR-0014): only structured .xlsx salary sources are
        // extracted this sprint. In-PDF salary grids (the "_Tablas*.pdf" docs)
        // are deferred — their convenios surface as coverage gaps below.
        $query = Document::query()
            ->with('documentType')
            ->whereHas('documentType', fn ($q) => $q->where('code', 'salary_tables'))
            ->where('storage_path', 'like', '%.xlsx');

        if ($uuid = $this->option('document')) {
            $query->where('uuid', $uuid);
        }

        $salaryDocs = $query->orderBy('id')->get();
        $withConvenio = $salaryDocs->whereNotNull('convenio_id');
        $pending = $salaryDocs->whereNull('convenio_id');

        $this->info("Salary documents: {$salaryDocs->count()} (with convenio: {$withConvenio->count()}, pending assignment: {$pending->count()})");

        $categoriesCreated = 0;
        $tablesWritten = 0;
        $rowsWritten = 0;
        /** @var list<string> $failures documents refused for a hard, non-silent reason */
        $failures = [];

        foreach ($withConvenio as $doc) {
            try {
                $result = $client->extractSalary($doc->storage_path, $doc->uuid);
            } catch (\Throwable $e) {
                $this->error("  [{$doc->id}] {$doc->source_filename}: ".$e->getMessage());
                $failures[] = "[{$doc->id}] {$doc->source_filename}: extract failed — ".$e->getMessage();

                continue;
            }

            $tables = $result['tables'] ?? [];
            foreach (($result['warnings'] ?? []) as $w) {
                $this->line("    · {$w}");
            }

            // A recognized grid that yielded nothing is a FAILURE, not a skip
            // (priority 2). `no_header` / `empty` stay benign: that is the
            // junk/notes-sheet case the parser is designed to ignore (the
            // derived .xlsx's own `Notes` sheet is one).
            $broken = array_values(array_filter(
                $result['sheet_diagnostics'] ?? [],
                fn ($d) => in_array($d['status'] ?? '', ['header_but_no_rows', 'header_maps_to_nothing'], true),
            ));
            if ($broken !== []) {
                foreach ($broken as $d) {
                    $this->error(sprintf(
                        "  [%d] %s: sheet '%s' — %s. NOTHING written for this document.",
                        $doc->id, $doc->source_filename, $d['sheet'] ?? '?', $d['status'],
                    ));
                    $failures[] = sprintf(
                        "[%d] %s: sheet '%s' %s",
                        $doc->id, $doc->source_filename, $d['sheet'] ?? '?', $d['status'],
                    );
                }
                Log::error('salary:import refused a document with a recognized-but-empty grid', [
                    'uuid' => $doc->uuid, 'file' => $doc->source_filename, 'sheets' => $broken,
                ]);

                continue;
            }

            if ($tables === []) {
                $this->error("  [{$doc->id}] {$doc->source_filename}: no salary tables parsed — nothing written.");
                $failures[] = "[{$doc->id}] {$doc->source_filename}: no salary tables parsed";

                continue;
            }

            DB::transaction(function () use ($doc, $tables, &$categoriesCreated, &$tablesWritten, &$rowsWritten) {
                foreach ($tables as $table) {
                    $year = $table['year'] ?? null;

                    $salaryTable = SalaryTable::updateOrCreate(
                        ['convenio_id' => $doc->convenio_id, 'year' => $year],
                        [
                            'validity_start' => $table['validity_start'] ?? null,
                            'validity_end' => $table['validity_end'] ?? null,
                            'source_document_id' => $doc->id,
                        ],
                    );

                    // Idempotent: replace this table's rows cleanly.
                    SalaryTableRow::where('salary_table_id', $salaryTable->id)->delete();
                    $tablesWritten++;

                    foreach ($table['rows'] ?? [] as $row) {
                        $name = trim((string) ($row['job_category_name'] ?? ''));
                        if ($name === '') {
                            continue;
                        }
                        [$category, $created] = $this->resolveCategory($doc->convenio_id, $name, $row['group_code'] ?? null);
                        $categoriesCreated += $created ? 1 : 0;

                        SalaryTableRow::create([
                            'salary_table_id' => $salaryTable->id,
                            'job_category_id' => $category->id,
                            'gross_annual' => $row['gross_annual'] ?? null,
                            'base_salary_monthly' => $row['base_salary_monthly'] ?? null,
                            'base_salary_monthly_label' => $row['base_salary_monthly_label'] ?? null,
                            'extra_pay' => $row['extra_pay'] ?? null,
                            'pagas_count' => $row['pagas_count'] ?? null,
                            'hourly_rate' => $row['hourly_rate'] ?? null,
                            'night_plus' => $row['night_plus'] ?? null,
                            'raw_values' => $row['raw_values'] ?? [],
                        ]);
                        $rowsWritten++;
                    }
                }
            });

            $this->line("  [{$doc->id}] {$doc->source_filename}: imported");
        }

        $this->newLine();
        if ($failures !== []) {
            // No success line, non-zero exit: an import that refused a document
            // must never read as a completed one.
            $this->error('Salary import FAILED for '.count($failures).' document(s) — wrote '
                ."{$tablesWritten} tables, {$rowsWritten} rows, {$categoriesCreated} new job categories from the rest:");
            foreach ($failures as $failure) {
                $this->error("  {$failure}");
            }
            $this->newLine();
            $this->error('Fix the source sheet (or its header mapping) and re-run. Exiting non-zero.');
            $this->reportPendingAndGaps($pending);

            return self::FAILURE;
        }

        $this->info("Salary import complete: {$tablesWritten} tables, {$rowsWritten} rows, {$categoriesCreated} new job categories.");

        $this->reportPendingAndGaps($pending);

        return self::SUCCESS;
    }

    /** @param  \Illuminate\Support\Collection<int,Document>  $pending */
    private function reportPendingAndGaps($pending): void
    {
        if ($pending->isNotEmpty()) {
            $this->newLine();
            $this->warn('Salary documents PENDING convenio assignment (ADR-0014, catch 4 — assign a convenio, then re-run):');
            foreach ($pending as $doc) {
                $this->line("  [{$doc->uuid}] {$doc->source_filename} ({$doc->tagging_status})");
                Log::warning('salary:import pending convenio assignment', ['uuid' => $doc->uuid, 'file' => $doc->source_filename]);
            }
        }

        $this->reportCoverageGaps();
    }

    /**
     * Resolve (or deliberately create) a per-convenio job category, matching on
     * normalized name within the convenio (no global dedup). Logged.
     *
     * @return array{0: ConvenioJobCategory, 1: bool} [category, created]
     */
    private function resolveCategory(int $convenioId, string $name, ?string $groupCode): array
    {
        $key = TextNormalizer::key($name);
        $existing = ConvenioJobCategory::where('convenio_id', $convenioId)->get()
            ->first(fn ($c) => TextNormalizer::key($c->name) === $key);

        if ($existing !== null) {
            // Heal stale stored values on re-import: the same normalized key may
            // be backed by an older, less-clean display value (e.g. a trailing
            // apostrophe `2.1'` from an Excel text cell). Keep the import the
            // source of truth for the cleaned name/group_code; stays idempotent.
            $changes = [];
            if ($existing->name !== $name) {
                $changes['name'] = $name;
            }
            if ($groupCode && $existing->group_code !== $groupCode) {
                $changes['group_code'] = $groupCode;
            } elseif ($groupCode && ! $existing->group_code) {
                $changes['group_code'] = $groupCode;
            }
            if ($changes !== []) {
                $existing->update($changes);
                Log::info('salary:import healed job category', [
                    'convenio_id' => $convenioId, 'id' => $existing->id, 'changes' => $changes,
                ]);
            }

            return [$existing, false];
        }

        $category = ConvenioJobCategory::create([
            'convenio_id' => $convenioId,
            'name' => $name,
            'group_code' => $groupCode,
        ]);
        Log::info('salary:import created job category', [
            'convenio_id' => $convenioId, 'name' => $name, 'group_code' => $groupCode,
        ]);

        return [$category, true];
    }

    /**
     * Coverage gaps made VISIBLE (ADR-0014): convenios with NO salary rows yet —
     * e.g. salary only in a PDF (Gipuzkoa Limpieza) — distinct from a legitimate
     * NULL pay concept. Listed, never silently blank.
     */
    private function reportCoverageGaps(): void
    {
        $noTable = Convenio::query()
            ->whereraw('not exists (select 1 from salary_tables st where st.convenio_id = convenios.id)')
            ->orderBy('numero')
            ->get(['numero', 'name']);

        $emptyTable = Convenio::query()
            ->whereraw('exists (select 1 from salary_tables st where st.convenio_id = convenios.id)')
            ->whereraw('not exists (select 1 from salary_table_rows r join salary_tables st on st.id = r.salary_table_id where st.convenio_id = convenios.id)')
            ->orderBy('numero')
            ->get(['numero', 'name']);

        $this->newLine();
        $this->warn('COVERAGE GAPS — convenios with NO salary rows yet ('.$noTable->count().'):');
        foreach ($noTable as $c) {
            $this->line("  no salary rows yet: {$c->numero} — {$c->name}");
        }
        if ($emptyTable->isNotEmpty()) {
            $this->warn('Convenios with a salary table but ZERO rows ('.$emptyTable->count().'):');
            foreach ($emptyTable as $c) {
                $this->line("  empty salary table: {$c->numero} — {$c->name}");
            }
        }
    }
}
