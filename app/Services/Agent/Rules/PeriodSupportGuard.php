<?php

namespace App\Services\Agent\Rules;

use App\Services\Agent\Rule;
use App\Services\Agent\ToolResult;
use App\Services\Agent\TurnState;
use App\Services\Agent\Verdict;
use App\Services\Answer\TurnOutcome;
use App\Services\ChatService;
use App\Services\GuardrailPolicy;
use Illuminate\Support\Carbon;

/**
 * Sprint 13, build step 5 (plan.md §F.15, user-directed follow-up after the
 * §F.4/§F.15 deviations were accepted) — `period` was dropped from
 * `ask_employee`'s `ALLOWED_TOPICS` in v1 (§F.15's own documented
 * alternative, `Rules\AskEmployeeWhitelist`'s docblock). No tool this sprint
 * accepts a caller-supplied `asOfDate` override, so there is genuinely no
 * way to HONOUR a past-period question yet — `SalaryPath`/`ReferenceFactPath`
 * /`ProsePath` all resolve the year from `asOfDate = today`, exactly as
 * classic does (§F.15). Silently answering an explicit-past-year question
 * with THIS year's data would be a confident WRONG answer — strictly worse
 * than escalating.
 *
 * Registered at `turn_start` (the one documented `RuleEngine` boundary no
 * rule had used before this) so it runs BEFORE round 0's short-circuit,
 * which would otherwise seed a wrong-year `salary_lookup`/`reference_fact`
 * answer before the planner or any tool-boundary rule ever runs.
 *
 * Deliberately NOT added to the shared `App\Services\Answer\PreModelGuards`
 * (used by BOTH engines): classic has the IDENTICAL underlying limitation
 * (§F.15 — `SalaryAnswerService::answer()` always uses `asOfDate = today`)
 * but is byte-identical by contract (§B.1, CP-0's golden traces) and out of
 * scope to change here. This is agent-only, new behaviour. Real `period`
 * support (threading a past `asOfDate` through the wrapped tools once
 * `ask_employee`'s `period` topic is built) is ticketed for post-pilot —
 * see review.md's step 5 section.
 */
final class PeriodSupportGuard implements Rule
{
    public function __construct(private readonly GuardrailPolicy $policy) {}

    public function id(): string
    {
        return 'period_support_guard';
    }

    public function evaluate(TurnState $state, ?array $call, ?ToolResult $result): Verdict
    {
        $year = $this->explicitPastYear($state->question);
        if ($year === null) {
            return Verdict::allow();
        }

        $trace = $state->trace;
        $trace['floor_decision'] = [
            'retrieval_score_floor' => $this->policy->retrievalFloor(),
            'answer_confidence_floor' => $this->policy->confidenceFloor(),
            'outcome' => 'escalate',
            'escalation_reason' => 'low_confidence',
            'note' => "explicit past year ({$year}) in the question — no supported way to answer for a period other than today (§F.15)",
        ];
        $trace['agent']['period_unsupported'] = ['matched_year' => $year];

        $outcome = new TurnOutcome('escalate', ChatService::ESCALATION_MESSAGE, [], $trace, 'low_confidence');

        return Verdict::forceEscalate($outcome, $this->id());
    }

    /**
     * The first bare 4-digit token in the question that parses as a
     * calendar year strictly before today's. Conservative on purpose — ANY
     * explicit past year is unsafe to silently answer for today, whether it
     * names a salary year, a convenio version, or a reference-fact
     * validity window; a false positive costs an escalation, a false
     * negative costs a confidently wrong figure.
     */
    private function explicitPastYear(string $question): ?int
    {
        $currentYear = Carbon::today()->year;
        if (preg_match_all('/\b(19|20)\d{2}\b/', $question, $matches) === 0) {
            return null;
        }

        foreach ($matches[0] as $match) {
            $year = (int) $match;
            if ($year < $currentYear) {
                return $year;
            }
        }

        return null;
    }
}
