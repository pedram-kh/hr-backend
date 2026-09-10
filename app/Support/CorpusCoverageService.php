<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * CorpusCoverageService — Sprint 8, Step 2 (plan.md §5, ADR-0030).
 *
 * `corpus:coverage` did not exist as code anywhere before Sprint 8 —
 * `hr-docs/corpus-coverage.md` was produced by an LLM agent running ad-hoc SQL
 * by hand in a chat session (its own closing lines say so). This class is the
 * shared query BOTH the admin Cobertura screen and the `corpus:coverage`
 * artisan export call — the "the screen and the ledger share one query" hard
 * constraint is true by construction (§5.6's agreement test asserts exactly
 * this: two calls to `grid()` return byte-identical data).
 *
 * Sits BESIDE `KnowledgeMap::coverageGaps()` (Sprint 3), not replacing it:
 * `KnowledgeMap` stays the document-level staleness/mistag view backing the
 * Sprint-3 Knowledge Map; this is a new, CONVENIO-level grid (prose / salary /
 * facts / rulings × every registry convenio), reusing
 * `KnowledgeMap::PROSE_TYPE_CODES`/`proseTypeIds()` rather than duplicating it.
 *
 * Reason codes reuse the ledger's 8-code vocabulary (`corpus-coverage-ledger-
 * prompt.md`), now assigned by code instead of by hand. Not every code maps
 * cleanly onto a convenio-level cell — `NO_CONVENIO_MATCH`/`MISTAG` describe
 * DOCUMENTS with no (or a wrong) convenio tag, not a convenio's own gap — this
 * is stated in the ledger's own Part 4 too (several full-gap rows carry no
 * code at all, just the prose note "no docs at all"). Where no code fits, this
 * service leaves `reason_code` null and states the situation in `detail`
 * rather than forcing a bad fit — the same honesty rule the sprint's plan
 * applies everywhere else.
 */
class CorpusCoverageService
{
    /** The 8 reason codes from `corpus-coverage-ledger-prompt.md`, assigned by code (plan.md §5.4). */
    public const REASON_SCAN_NO_TEXT = 'SCAN_NO_TEXT';

    public const REASON_UNDER_REVIEW_SCOPE = 'UNDER_REVIEW_SCOPE';

    public const REASON_NO_CONVENIO_MATCH = 'NO_CONVENIO_MATCH';

    public const REASON_SALARY_PDF_NOT_IMPORTED = 'SALARY_PDF_NOT_IMPORTED';

    public const REASON_EXPIRED_NO_SUCCESSOR = 'EXPIRED_NO_SUCCESSOR';

    public const REASON_GROUP_SCOPED_FACT = 'GROUP_SCOPED_FACT';

    public const REASON_FACT_NEEDS_REVIEW = 'FACT_NEEDS_REVIEW';

    public const REASON_MISTAG = 'MISTAG';

    /**
     * The convenio-level grid — one row per registry convenio, ordered by id
     * (deterministic order is what makes the agreement/byte-stable tests
     * meaningful, §5.6/§11). Pure read; no write, no side effect, ever.
     *
     * @return list<array<string,mixed>>
     */
    public function grid(?Carbon $asOf = null): array
    {
        $asOf = $asOf ?? Carbon::today();
        $year = $asOf->year;

        $convenios = DB::table('convenios as cv')
            ->join('territories as t', 't.id', '=', 'cv.territory_id')
            ->join('sectors as s', 's.id', '=', 'cv.sector_id')
            ->select('cv.id', 'cv.numero', 'cv.name', 't.name as territory', 's.name as sector')
            ->orderBy('cv.id')
            ->get();

        $headcounts = $this->headcounts();

        $rows = [];
        foreach ($convenios as $cv) {
            $rows[] = [
                'convenio_id' => $cv->id,
                'numero' => $cv->numero,
                'name' => $cv->name,
                'territory' => $cv->territory,
                'sector' => $cv->sector,
                'headcount' => $headcounts[$cv->id] ?? 0,
                'prose' => $this->proseCell((int) $cv->id),
                'salary' => $this->salaryCell((int) $cv->id, $year),
                'facts' => $this->factsCell((int) $cv->id),
                'rulings' => $this->rulingsCell((int) $cv->id),
            ];
        }

        return $rows;
    }

    /** @return array<int,int> convenio_id => active headcount (plan.md §5.2). */
    public function headcounts(): array
    {
        return DB::table('employees')
            ->where('status', 'active')
            ->select('convenio_id', DB::raw('count(*) as headcount'))
            ->groupBy('convenio_id')
            ->pluck('headcount', 'convenio_id')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /** Group-level headcount, reusing the Sprint-7f `convenio_group_id` column (plan.md §5.2). */
    public function groupHeadcounts(): array
    {
        return DB::table('employees')
            ->where('status', 'active')
            ->whereNotNull('convenio_group_id')
            ->select('convenio_group_id', DB::raw('count(*) as headcount'))
            ->groupBy('convenio_group_id')
            ->pluck('headcount', 'convenio_group_id')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /**
     * Prose ✓/✗ (plan.md §5.1): active prose doc with ≥1 chunk = covered.
     * Amendment-only flag: a `partial_agreement` covers but no
     * `convenio_text`/`national_law` does (convenio 9's exact real case).
     *
     * @return array<string,mixed>
     */
    private function proseCell(int $convenioId): array
    {
        $proseIds = KnowledgeMap::proseTypeIds();
        if ($proseIds === []) {
            return ['covered' => false, 'amendment_only' => false, 'reason_code' => null, 'detail' => 'no prose document types configured'];
        }

        $activeDocs = DB::table('documents')
            ->where('convenio_id', $convenioId)
            ->whereIn('document_type_id', $proseIds)
            ->where('retrieval_status', 'active')
            ->select('id', 'document_type_id')
            ->get();

        $substantiveTypeIds = DB::table('document_types')
            ->whereIn('code', ['convenio_text', 'national_law'])
            ->pluck('id')
            ->all();

        $activeWithChunks = [];
        $activeZeroChunk = [];
        foreach ($activeDocs as $doc) {
            $chunkCount = (int) DB::table('document_chunks')->where('document_id', $doc->id)->count();
            if ($chunkCount > 0) {
                $activeWithChunks[] = $doc;
            } else {
                $activeZeroChunk[] = $doc;
            }
        }

        if ($activeWithChunks !== []) {
            $hasSubstantive = collect($activeWithChunks)->contains(fn ($d) => in_array($d->document_type_id, $substantiveTypeIds, true));

            return [
                'covered' => true,
                'amendment_only' => ! $hasSubstantive,
                'reason_code' => null,
                'detail' => $hasSubstantive ? null : 'covered only by a partial_agreement amendment — base text still missing',
            ];
        }

        if ($activeZeroChunk !== []) {
            return ['covered' => false, 'amendment_only' => false, 'reason_code' => self::REASON_SCAN_NO_TEXT, 'detail' => 'active prose document(s) exist but have 0 chunks'];
        }

        $underReview = DB::table('documents')
            ->where('convenio_id', $convenioId)
            ->whereIn('document_type_id', $proseIds)
            ->where('tagging_status', 'under_review')
            ->exists();
        if ($underReview) {
            return ['covered' => false, 'amendment_only' => false, 'reason_code' => self::REASON_UNDER_REVIEW_SCOPE, 'detail' => 'prose document(s) exist but tagging is not yet verified'];
        }

        $historicalExists = DB::table('documents')
            ->where('convenio_id', $convenioId)
            ->whereIn('document_type_id', $proseIds)
            ->where('retrieval_status', 'historical')
            ->exists();
        if ($historicalExists) {
            return ['covered' => false, 'amendment_only' => false, 'reason_code' => self::REASON_EXPIRED_NO_SUCCESSOR, 'detail' => 'only historical/expired prose exists, no active successor'];
        }

        return ['covered' => false, 'amendment_only' => false, 'reason_code' => null, 'detail' => 'no prose document at all for this convenio'];
    }

    /**
     * Salary ✓/✗ for the CURRENT year (plan.md §5.1/§12 resolved q5): "current
     * year" = `Carbon::today()->year` at query time, matching
     * `SalaryAnswerService::resolveTable()`'s own as-of-year resolution
     * (read, not re-derived) — never a fixed baked-in year.
     *
     * @return array<string,mixed>
     */
    private function salaryCell(int $convenioId, int $year): array
    {
        $exact = DB::table('salary_tables')->where('convenio_id', $convenioId)->where('year', $year)->exists();
        if ($exact) {
            return ['covered' => true, 'year' => $year, 'reason_code' => null, 'detail' => null];
        }

        $mostRecentPastYear = DB::table('salary_tables')
            ->where('convenio_id', $convenioId)
            ->whereNotNull('year')
            ->where('year', '<=', $year)
            ->orderByDesc('year')
            ->value('year');
        if ($mostRecentPastYear !== null) {
            return ['covered' => true, 'year' => $mostRecentPastYear, 'reason_code' => null, 'detail' => "table is {$mostRecentPastYear}, carried forward per SalaryAnswerService's own year-fallback"];
        }

        $futureOnly = DB::table('salary_tables')->where('convenio_id', $convenioId)->whereNotNull('year')->where('year', '>', $year)->exists();
        if ($futureOnly) {
            return ['covered' => false, 'year' => null, 'reason_code' => null, 'detail' => 'only a not-yet-effective (future) salary table exists'];
        }

        $pdfUnconverted = DB::table('documents as d')
            ->join('document_types as dt', 'dt.id', '=', 'd.document_type_id')
            ->where('d.convenio_id', $convenioId)
            ->where('dt.code', 'salary_tables')
            ->where('d.storage_path', 'like', '%.pdf')
            ->exists();
        if ($pdfUnconverted) {
            return ['covered' => false, 'year' => null, 'reason_code' => self::REASON_SALARY_PDF_NOT_IMPORTED, 'detail' => 'a PDF salary document exists but has not been converted/imported'];
        }

        return ['covered' => false, 'year' => null, 'reason_code' => null, 'detail' => 'no salary data at all for this convenio'];
    }

    /**
     * Facts ✓/✗ + group-only flag (plan.md §5.1): "group-only?" = every
     * verified fact for this convenio carries a non-null `job_category_id` or
     * `group_label` (no convenio-wide verified fact exists).
     *
     * @return array<string,mixed>
     */
    private function factsCell(int $convenioId): array
    {
        $verified = DB::table('reference_facts')
            ->where('convenio_id', $convenioId)
            ->where('status', 'verified')
            ->select('job_category_id', 'group_label')
            ->get();

        if ($verified->isNotEmpty()) {
            $groupOnly = $verified->every(fn ($f) => $f->job_category_id !== null || $f->group_label !== null);

            return ['covered' => true, 'group_only' => $groupOnly, 'reason_code' => null, 'detail' => null];
        }

        $needsReview = DB::table('reference_facts')->where('convenio_id', $convenioId)->where('status', 'needs_review')->exists();
        if ($needsReview) {
            return ['covered' => false, 'group_only' => false, 'reason_code' => self::REASON_FACT_NEEDS_REVIEW, 'detail' => 'a proposed fact exists but no human has verified it yet'];
        }

        return ['covered' => false, 'group_only' => false, 'reason_code' => null, 'detail' => 'no reference fact at all for this convenio'];
    }

    /** Rulings ✓/✗ (plan.md §5.1): an active, internal_hr_ruling-authority document.
     *
     * @return array<string,mixed>
     */
    private function rulingsCell(int $convenioId): array
    {
        $covered = DB::table('documents')
            ->where('convenio_id', $convenioId)
            ->where('authority_level', 'internal_hr_ruling')
            ->where('retrieval_status', 'active')
            ->exists();

        return ['covered' => $covered, 'reason_code' => null, 'detail' => null];
    }

    /**
     * No-registry-convenio rows (plan.md §5.3, resolved open question #6):
     * "the registry import's source list defines 'should exist'" — resolved
     * concretely as: an employee whose OWN convenio's territory does not
     * match the employee's own `territory_id` (i.e. they are covered only by
     * a broader-scope — regional/national — convenio) is real signal that
     * their actual (territory, sector) combination has no LOCAL registry
     * convenio at all. A pair is only reported when literally zero
     * `convenios` rows exist for that exact (territory_id, sector_id) — this
     * is the "have employees but no registry convenio at all" case the spec
     * names (`sprint-08-spec.md:26`), made concrete against this schema.
     *
     * @return list<array<string,mixed>>
     */
    public function noRegistryConvenioRows(): array
    {
        $scopes = DB::table('employees as e')
            ->join('convenios as cv', 'cv.id', '=', 'e.convenio_id')
            ->whereColumn('cv.territory_id', '!=', 'e.territory_id')
            ->select('e.territory_id', 'cv.sector_id')
            ->distinct()
            ->get();

        $rows = [];
        foreach ($scopes as $scope) {
            $localCount = DB::table('convenios')
                ->where('territory_id', $scope->territory_id)
                ->where('sector_id', $scope->sector_id)
                ->count();
            if ($localCount > 0) {
                continue; // a local registry row already exists for this exact pair — not a gap.
            }

            $headcount = DB::table('employees as e')
                ->join('convenios as cv', 'cv.id', '=', 'e.convenio_id')
                ->where('e.territory_id', $scope->territory_id)
                ->where('cv.sector_id', $scope->sector_id)
                ->whereColumn('cv.territory_id', '!=', 'e.territory_id')
                ->where('e.status', 'active')
                ->count();

            $territory = DB::table('territories')->where('id', $scope->territory_id)->value('name');
            $sector = DB::table('sectors')->where('id', $scope->sector_id)->value('name');

            $rows[] = [
                'territory_id' => $scope->territory_id,
                'territory' => $territory,
                'sector_id' => $scope->sector_id,
                'sector' => $sector,
                'headcount' => $headcount,
                'reason_code' => self::REASON_NO_CONVENIO_MATCH,
                'detail' => 'employees in this territory are currently covered only by a broader-scope (regional/national) convenio for this sector; no local registry convenio exists',
            ];
        }

        // Rank by people affected, per spec.
        usort($rows, fn ($a, $b) => $b['headcount'] <=> $a['headcount']);

        return $rows;
    }

    /**
     * Render the grid + no-registry rows as the exact markdown table shape
     * `corpus-coverage.md` Part 4 already uses (plan.md §5.6) — the SAME
     * method the screen's export button and the `corpus:coverage` artisan
     * command both call, which is what makes the agreement test trivial.
     * Deterministic (stable row order, no timestamp/random content) so two
     * calls against the same DB state are byte-identical (§11's byte-stable
     * export test).
     */
    public function toMarkdown(array $grid, array $noRegistryRows, Carbon $asOf): string
    {
        $lines = [];
        $lines[] = '# Corpus coverage grid (generated by `corpus:coverage` / CorpusCoverageService)';
        $lines[] = '';
        $lines[] = sprintf('Generated: %s (as-of year: %d)', $asOf->toDateString(), $asOf->year);
        $lines[] = '';
        $lines[] = '| convenio | territory | sector | headcount | prose | salary | facts | rulings |';
        $lines[] = '|---|---|---|---|---|---|---|---|';
        foreach ($grid as $row) {
            $prose = $row['prose']['covered']
                ? ($row['prose']['amendment_only'] ? '⚠ amendment-only' : '✓')
                : '✗'.($row['prose']['reason_code'] ? " `{$row['prose']['reason_code']}`" : '');
            $salary = $row['salary']['covered']
                ? '✓ ('.$row['salary']['year'].')'
                : '✗'.($row['salary']['reason_code'] ? " `{$row['salary']['reason_code']}`" : '');
            $facts = $row['facts']['covered']
                ? ($row['facts']['group_only'] ? '✓ (group-only)' : '✓')
                : '✗'.($row['facts']['reason_code'] ? " `{$row['facts']['reason_code']}`" : '');
            $rulings = $row['rulings']['covered'] ? '✓' : '✗';

            $lines[] = sprintf(
                '| %s %s | %s | %s | %d | %s | %s | %s | %s |',
                $row['numero'],
                $row['name'],
                $row['territory'],
                $row['sector'],
                $row['headcount'],
                $prose,
                $salary,
                $facts,
                $rulings,
            );
        }

        $lines[] = '';
        $lines[] = '## No-registry-convenio rows (plan.md §5.3)';
        $lines[] = '';
        if ($noRegistryRows === []) {
            $lines[] = '(none found)';
        } else {
            $lines[] = '| territory | sector | headcount | reason |';
            $lines[] = '|---|---|---|---|';
            foreach ($noRegistryRows as $row) {
                $lines[] = sprintf('| %s | %s | %d | `%s` — %s |', $row['territory'], $row['sector'], $row['headcount'], $row['reason_code'], $row['detail']);
            }
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * The gaps-closed trend (plan.md §5.5): "gaps closed per week" = a
     * self-join on `coverage_snapshots` — was ✗ on `$from`, is ✓ on `$to`,
     * same (convenio_id, knowledge_type) cell. No separate trend table (the
     * snapshot table's own history IS the trend).
     */
    public function gapsClosedBetween(Carbon $from, Carbon $to): int
    {
        return (int) DB::table('coverage_snapshots as a')
            ->join('coverage_snapshots as b', function ($j) {
                $j->on('a.convenio_id', '=', 'b.convenio_id')
                    ->on('a.knowledge_type', '=', 'b.knowledge_type');
            })
            ->where('a.snapshot_date', $from->toDateString())
            ->where('b.snapshot_date', $to->toDateString())
            ->where('a.covered', false)
            ->where('b.covered', true)
            ->count();
    }

    /**
     * One row per distinct snapshot date, with the total ✗ (gap) cell count
     * that date — the raw series a trend chart draws (Analítica/Cobertura's
     * `.chart-line`, plan.md §10). Every date `coverage:snapshot` has ever
     * written is real history, not synthesized backfill.
     *
     * @return list<array{date:string,gap_count:int,covered_count:int}>
     */
    public function snapshotTrend(int $limitDates = 30): array
    {
        $dates = DB::table('coverage_snapshots')
            ->select('snapshot_date')
            ->distinct()
            ->orderByDesc('snapshot_date')
            ->limit($limitDates)
            ->pluck('snapshot_date');

        $rows = DB::table('coverage_snapshots')
            ->whereIn('snapshot_date', $dates)
            ->select('snapshot_date', 'covered', DB::raw('count(*) as n'))
            ->groupBy('snapshot_date', 'covered')
            ->get()
            ->groupBy('snapshot_date');

        return $dates->sort()->values()->map(function ($date) use ($rows) {
            $forDate = $rows->get($date, collect());

            return [
                'date' => (string) $date,
                'gap_count' => (int) $forDate->firstWhere('covered', false)?->n ?? 0,
                'covered_count' => (int) $forDate->firstWhere('covered', true)?->n ?? 0,
            ];
        })->all();
    }

    /**
     * The full-gap convenios (every cell ✗ — the worst case) ranked by
     * headcount, per the eyes-on checklist's "full-gap convenios ranked by
     * headcount, reason codes with links" — one row per full-gap convenio,
     * carrying every cell's reason code + the `AdminLinks::coverage()` deep
     * link so a reviewer can jump straight from the ranked list to the map.
     *
     * @param  list<array<string,mixed>>  $grid
     * @return list<array<string,mixed>>
     */
    public function fullGapConvenios(array $grid): array
    {
        $rows = [];
        foreach ($grid as $row) {
            $allGaps = ! $row['prose']['covered'] && ! $row['salary']['covered'] && ! $row['facts']['covered'] && ! $row['rulings']['covered'];
            if (! $allGaps) {
                continue;
            }
            $rows[] = [
                'convenio_id' => $row['convenio_id'],
                'numero' => $row['numero'],
                'name' => $row['name'],
                'territory' => $row['territory'],
                'sector' => $row['sector'],
                'headcount' => $row['headcount'],
                'reason_codes' => array_values(array_filter([
                    $row['prose']['reason_code'], $row['salary']['reason_code'],
                    $row['facts']['reason_code'], $row['rulings']['reason_code'],
                ])),
                'link' => AdminLinks::coverage($row['convenio_id']),
            ];
        }

        usort($rows, fn ($a, $b) => $b['headcount'] <=> $a['headcount']);

        return $rows;
    }
}
