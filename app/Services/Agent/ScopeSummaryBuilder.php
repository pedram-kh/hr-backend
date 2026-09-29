<?php

namespace App\Services\Agent;

use App\Models\Employee;
use App\Models\ReferenceFact;
use App\Models\SalaryTable;
use App\Models\Topic;
use App\Support\CorpusCoverageService;
use Illuminate\Support\Carbon;

/**
 * Sprint 13, build step 6 (plan.md §C.10, "What the planner sees about the
 * employee") — the `scope_summary` handed to the real planner. Deliberately
 * narrow: convenio/territory NAMES (never ids the model could echo back as
 * if they were meaningful on their own), category/group labels or their
 * explicit absence, a few booleans, and topic NAMES with a verified in-scope
 * fact — never a figure, a document, a chunk, or any PII (§C.10's own "never
 * name/email/uuid/external id/work location" list).
 */
final class ScopeSummaryBuilder
{
    public function __construct(
        private readonly CorpusCoverageService $coverage,
    ) {}

    /** @return array<string,mixed> */
    public function build(Employee $employee, Carbon $asOfDate): array
    {
        $employee->loadMissing(['convenio', 'territory', 'jobCategory', 'convenioGroup']);

        $convenioId = $employee->convenio_id;

        return [
            'convenio_name' => $employee->convenio?->name,
            'territory_name' => $employee->territory?->name,
            'employment_type' => $employee->employment_type,
            'category_name' => $employee->jobCategory?->name ?? 'sin categoría',
            'group_label' => $employee->convenioGroup?->pathLabel() ?? 'sin grupo',
            'start_date_set' => $employee->start_date !== null,
            'has_salary_table' => $convenioId !== null && SalaryTable::where('convenio_id', $convenioId)->exists(),
            'prose_gap' => $convenioId !== null ? $this->coverage->classifyProseGap($convenioId) : CorpusCoverageService::PROSE_NEVER_INGESTED,
            'verified_topics' => $convenioId !== null ? $this->verifiedTopicNames($convenioId, $asOfDate) : [],
            // Step 9 (§B.6) ships the lane and its real config/guardrail toggle;
            // until then there is nothing to enable, so this is always false —
            // not a stub omission, an honest "does not exist yet" reading of
            // an env key/guardrail that literally is not wired anywhere.
            'general_lane_enabled' => (bool) config('hr.general_lane.enabled', false),
        ];
    }

    /** @return list<string> approved topic names with a verified, in-scope, in-validity fact (mirrors `ReferenceFactRouter`'s own existence check, generalized to ALL topics rather than one candidate set). */
    private function verifiedTopicNames(int $convenioId, Carbon $asOfDate): array
    {
        $asOf = $asOfDate->toDateString();

        return Topic::query()
            ->where('status', 'approved')
            ->whereExists(function ($query) use ($convenioId, $asOf) {
                $query->selectRaw('1')
                    ->from((new ReferenceFact)->getTable())
                    ->whereColumn('topic_id', 'topics.id')
                    ->where('convenio_id', $convenioId)
                    ->where('status', 'verified')
                    ->where(fn ($q) => $q->whereNull('validity_start')->orWhere('validity_start', '<=', $asOf))
                    ->where(fn ($q) => $q->whereNull('validity_end')->orWhere('validity_end', '>=', $asOf));
            })
            ->orderBy('name')
            ->pluck('name')
            ->values()
            ->all();
    }
}
