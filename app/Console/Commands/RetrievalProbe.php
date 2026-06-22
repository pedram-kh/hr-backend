<?php

namespace App\Console\Commands;

use App\Models\Convenio;
use App\Models\ConvenioJobCategory;
use App\Models\Document;
use App\Models\Employee;
use App\Models\SalaryTable;
use App\Services\ExtractionClient;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Verification harness (NOT a UI) for the Sprint-2a retrieval substrate.
 *
 * For a given profile + question + date it prints: the RESOLVED SCOPE, the
 * ELIGIBLE PROSE CHUNKS (scope-prefiltered then exact-ranked, with score +
 * source doc/page), and the ELIGIBLE SALARY ROWS (SQL by convenio + year + job
 * category) or the coverage-gap message. This exercises the whole substrate and
 * seeds 2b's trace shape. No router / answer LLM (that is 2b).
 *
 * hr-backend resolves scope (deterministic, legal weight) and runs the salary
 * SQL itself; it calls hr-ai /retrieve for the vector primitive (ADR-0007).
 *
 * Full-recall check (catch 2): the harness asserts the ANN layer never drops an
 * eligible chunk (returned == eligible_total when eligible_total ≤ k).
 */
class RetrievalProbe extends Command
{
    protected $signature = 'retrieval:probe
        {--email= : resolve scope from a real employee}
        {--convenio= : convenio numero (overrides --email)}
        {--job-category= : job category name (salary filter)}
        {--question= : the prose question}
        {--date= : as-of date (YYYY-MM-DD, default today)}
        {--mode=both : prose|salary|both}
        {--include-historical : include historical docs (time-scoped questions)}
        {--k=8 : top-k chunks}';

    protected $description = 'Probe the retrieval substrate: resolved scope + eligible chunks + eligible salary rows.';

    public function handle(ExtractionClient $client): int
    {
        $date = $this->option('date') ? Carbon::parse($this->option('date')) : Carbon::today();
        $mode = $this->option('mode');
        $k = (int) $this->option('k');
        $question = (string) ($this->option('question') ?? '');

        // --- Resolve scope (deterministic) ---
        $convenio = null;
        $jobCategory = null;
        $employee = null;

        if ($num = $this->option('convenio')) {
            $convenio = Convenio::where('numero', $num)->first();
            if (! $convenio) {
                $this->error("Convenio {$num} not found.");

                return self::FAILURE;
            }
        } elseif ($email = $this->option('email')) {
            $employee = Employee::where('email', strtolower($email))->first();
            if (! $employee) {
                $this->error("Employee {$email} not found.");

                return self::FAILURE;
            }
            $convenio = $employee->convenio;
            $jobCategory = $employee->jobCategory;
        }

        if ($name = $this->option('job-category')) {
            $jobCategory = ConvenioJobCategory::when($convenio, fn ($q) => $q->where('convenio_id', $convenio->id))
                ->where('name', $name)->first();
        }

        $statuses = $this->option('include-historical') ? ['active', 'historical'] : ['active'];

        $this->line(str_repeat('=', 72));
        $this->info('RESOLVED SCOPE');
        $this->line('  convenio:        '.($convenio ? "{$convenio->numero} — {$convenio->name}" : '(none — national-law only)'));
        $this->line('  territory:       '.($convenio?->territory?->name ?? '—').' / sector: '.($convenio?->sector?->name ?? '—'));
        $this->line('  job_category:    '.($jobCategory?->name ?? '(none on profile)'));
        $this->line('  retrieval_status:'.' ['.implode(', ', $statuses).']  (national_law always universal)');
        $this->line('  as_of_date:      '.$date->toDateString());
        $this->line('  question:        '.($question ?: '(none)'));

        // --- Prose: vector retrieval (scope-prefilter → exact rank) ---
        if (in_array($mode, ['prose', 'both'], true) && $question !== '') {
            $this->newLine();
            $this->info('ELIGIBLE PROSE CHUNKS (scope-prefiltered before similarity)');
            try {
                $resp = $client->retrieve([
                    'query' => $question,
                    'convenio_id' => $convenio?->id,
                    'include_national_law' => true,
                    'retrieval_status' => $statuses,
                    'as_of_date' => $date->toDateString(),
                    'k' => $k,
                ]);
                $chunks = $resp['chunks'] ?? [];
                $eligibleTotal = (int) ($resp['eligible_total'] ?? 0);
                $this->line("  eligible chunk set size: {$eligibleTotal}; returned top-".count($chunks));

                // Full-recall assertion (catch 2).
                if ($eligibleTotal <= $k && count($chunks) !== min($eligibleTotal, $k)) {
                    $this->error('  FULL-RECALL ASSERTION FAILED: returned fewer than the eligible set — ANN dropped an eligible chunk.');
                } else {
                    $this->line('  full-recall check: OK (no eligible chunk dropped by the ANN layer)');
                }

                $docTitles = Document::whereIn('id', collect($chunks)->pluck('document_id'))->get()
                    ->keyBy('id');
                foreach ($chunks as $c) {
                    $doc = $docTitles->get($c['document_id']);
                    $src = $doc ? $doc->source_filename : "doc#{$c['document_id']}";
                    $pages = "p{$c['page_from']}".($c['page_to'] != $c['page_from'] ? "-{$c['page_to']}" : '');
                    $snippet = trim(preg_replace('/\s+/', ' ', mb_substr((string) $c['content'], 0, 90)));
                    $this->line(sprintf('  [%.3f] %s %s (chunk %d): %s…', $c['score'], $src, $pages, $c['chunk_index'], $snippet));
                }
            } catch (\Throwable $e) {
                $this->error('  /retrieve failed: '.$e->getMessage());
            }
        }

        // --- Salary: SQL by convenio + year + job category (no vector search) ---
        if (in_array($mode, ['salary', 'both'], true) && $convenio) {
            $this->newLine();
            $this->info('ELIGIBLE SALARY ROWS (SQL: convenio + year + job category)');
            $this->probeSalary($convenio, $jobCategory, $date);
        }

        $this->line(str_repeat('=', 72));

        return self::SUCCESS;
    }

    private function probeSalary(Convenio $convenio, ?ConvenioJobCategory $jobCategory, Carbon $date): void
    {
        // Resolve the year: prefer the table for the as-of year, else the latest
        // table not after it, else the latest available.
        $year = $date->year;
        $table = SalaryTable::where('convenio_id', $convenio->id)->where('year', $year)->first()
            ?? SalaryTable::where('convenio_id', $convenio->id)->where('year', '<=', $year)->orderByDesc('year')->first()
            ?? SalaryTable::where('convenio_id', $convenio->id)->orderByDesc('year')->first();

        if (! $table) {
            // Coverage gap made visible (ADR-0014) — NOT a silent blank.
            $this->warn("  no salary rows yet for convenio {$convenio->numero} (salary may be PDF-only — coverage gap, not an error)");

            return;
        }

        $rows = $table->rows()->with('jobCategory')
            ->when($jobCategory, fn ($q) => $q->where('job_category_id', $jobCategory->id))
            ->get();

        if ($rows->isEmpty()) {
            $this->warn("  salary table for {$convenio->numero} year {$table->year} has no matching rows".($jobCategory ? " for category '{$jobCategory->name}'" : ''));

            return;
        }

        $this->line("  salary_table: convenio {$convenio->numero}, year {$table->year}".($jobCategory ? ", category {$jobCategory->name}" : ' (all categories)'));
        foreach ($rows as $r) {
            $this->line(sprintf(
                '  - %-28s gross_annual=%s base/mes(14)=%s €/h=%s',
                mb_substr((string) $r->jobCategory?->name, 0, 28),
                $this->money($r->gross_annual),
                $this->money($r->base_salary_monthly),
                $r->hourly_rate !== null ? number_format((float) $r->hourly_rate, 4) : '—',
            ));
        }
    }

    private function money($v): string
    {
        return $v !== null ? number_format((float) $v, 2) : '—';
    }
}
