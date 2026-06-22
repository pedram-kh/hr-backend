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
 * 14/12 canonical mapping (catch 3): base_salary_monthly = gross_annual/14,
 * num_payments = 14; the /12 figure and every original column are kept verbatim
 * in raw_values. hr-ai computes the typed columns; this command persists them.
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

        foreach ($withConvenio as $doc) {
            try {
                $result = $client->extractSalary($doc->storage_path, $doc->uuid);
            } catch (\Throwable $e) {
                $this->error("  [{$doc->id}] {$doc->source_filename}: ".$e->getMessage());

                continue;
            }

            $tables = $result['tables'] ?? [];
            foreach (($result['warnings'] ?? []) as $w) {
                $this->line("    · {$w}");
            }
            if ($tables === []) {
                $this->warn("  [{$doc->id}] {$doc->source_filename}: no salary tables parsed");

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
                            'extra_pay' => $row['extra_pay'] ?? null,
                            'num_payments' => $row['num_payments'] ?? null,
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
        $this->info("Salary import complete: {$tablesWritten} tables, {$rowsWritten} rows, {$categoriesCreated} new job categories.");

        if ($pending->isNotEmpty()) {
            $this->newLine();
            $this->warn('Salary documents PENDING convenio assignment (ADR-0014, catch 4 — assign a convenio, then re-run):');
            foreach ($pending as $doc) {
                $this->line("  [{$doc->uuid}] {$doc->source_filename} ({$doc->tagging_status})");
                Log::warning('salary:import pending convenio assignment', ['uuid' => $doc->uuid, 'file' => $doc->source_filename]);
            }
        }

        $this->reportCoverageGaps();

        return self::SUCCESS;
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
