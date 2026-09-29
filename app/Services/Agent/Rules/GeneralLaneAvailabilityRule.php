<?php

namespace App\Services\Agent\Rules;

use App\Services\Agent\Rule;
use App\Services\Agent\ToolResult;
use App\Services\Agent\TurnState;
use App\Services\Agent\Verdict;
use App\Services\Answer\TurnOutcome;
use App\Services\ChatService;
use App\Services\GuardrailPolicy;

/**
 * Sprint 13, build step 9 (plan.md §B.6.1) — `pre_call:general_knowledge`.
 * Condition 2 was AMENDED at CP-1 (F.8 amendment): the lane may also open after
 * Check A passed and ONLY the entailment gate failed — see {@see CorpusMiss}.
 * Enforces conditions 1, 2 and 4 of §B.6.1's pre-call list (condition 3 —
 * not salary/SMI/a verified reference-fact route/the aggregation shape — is
 * already structural: those routes each spend their own synthesis call and
 * terminate before the planner could ever reach `general_knowledge` in the
 * same turn; condition 5 — off-domain guardrails ran first — is enforced
 * upstream of the whole agent loop, `ChatService::handleMessage()`).
 *
 * `AgentServiceProvider` also conditionally registers the tool itself only
 * when {@see GuardrailPolicy::generalLaneEnabled()}'s BASELINE half
 * (`config('hr.general_lane.enabled')`) is true, so a fully-disabled lane is
 * "not even in the planner's tool list" per §B.6.1 condition 1's own wording
 * — this rule is the belt-and-suspenders check for the admin-toggle half
 * (which can change between requests without a deploy) and for any stale/
 * malformed call that names the tool anyway.
 */
final class GeneralLaneAvailabilityRule implements Rule
{
    public function __construct(private readonly GuardrailPolicy $guardrails) {}

    public function id(): string
    {
        return 'general_lane_availability';
    }

    public function evaluate(TurnState $state, ?array $call, ?ToolResult $result): Verdict
    {
        if ($result !== null) {
            // post_call boundary — this rule only guards pre_call.
            return Verdict::allow();
        }

        // Condition 1: env baseline AND Guardarraíles toggle.
        if (! $this->guardrails->generalLaneEnabled()) {
            return Verdict::deny('general_knowledge está desactivado', $this->id());
        }

        // Condition 2 (as amended at CP-1, F.8): convenio_search (or
        // national_law) already ran THIS turn and either (a) produced no
        // material clearing Check A, or (b) cleared Check A AND Check B AND
        // the figure-guard but failed ONLY the per-claim entailment gate —
        // never after a figure-guard / Check-B / truncation / other verdict.
        // The lane is last by rule, never first by prompt.
        $miss = $this->priorCorpusMiss($state);
        if ($miss === null) {
            return Verdict::deny('intenta convenio_search (o national_law) primero', $this->id());
        }

        // (b) is only ever handed over for questions passing the explanatory
        // pre-screen; a hit there is a DENY (the corpus escalation stands), not
        // the `general_lane_blocked` force below, which is reserved for the
        // original (a) path.
        if ($miss === CorpusMiss::ENTAILMENT_ONLY && GeneralLanePostCheck::questionPrescreenHit($state->question)) {
            return Verdict::deny('la pregunta pide una cantidad o un derecho concreto; no aplica información general', $this->id());
        }

        // Condition 4: the question pre-screen (figures/entitlement/timing
        // phrasing) — a hit here means the employee is asking for THEIR OWN
        // concrete figure, never answerable by a general-concept lane.
        if (GeneralLanePostCheck::questionPrescreenHit($state->question)) {
            $trace = $state->trace;
            $trace['floor_decision'] = [
                'path' => 'general_knowledge',
                'outcome' => 'escalate',
                'escalation_reason' => 'general_lane_blocked',
                'authority_used' => [],
                'note' => 'general lane blocked by the question pre-screen',
            ];
            $trace['general_lane']['postcheck'] = [
                'passed' => false,
                'hits' => [['pattern_id' => 'question_prescreen', 'matched_span' => $state->question]],
            ];
            $outcome = new TurnOutcome('escalate', ChatService::EMPLOYEE_ESCALATION_MESSAGE, [], $trace, 'general_lane_blocked');

            return Verdict::forceEscalate($outcome, $this->id());
        }

        return Verdict::allow();
    }

    /**
     * `CorpusMiss::CHECK_A_MISS` | `CorpusMiss::ENTAILMENT_ONLY` | null.
     * A NO_MATERIAL carrying a stashed outcome is RE-classified here (the
     * tools decided once; the rule does not trust the label) — a stashed
     * outcome that is neither shape denies. A NO_MATERIAL with no stashed
     * outcome keeps its pre-amendment meaning (a Check-A miss).
     */
    private function priorCorpusMiss(TurnState $state): ?string
    {
        foreach (['convenio_search', 'national_law'] as $tool) {
            $material = $state->material[$tool] ?? null;
            if (! $material instanceof ToolResult || $material->status !== ToolResult::NO_MATERIAL) {
                continue;
            }
            if ($material->terminalOutcome === null) {
                return CorpusMiss::CHECK_A_MISS;
            }

            $kind = CorpusMiss::classify($material->terminalOutcome);
            if ($kind !== null) {
                return $kind;
            }
        }

        return null;
    }
}
