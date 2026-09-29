<?php

namespace App\Services\Agent;

use App\Services\Answer\TurnOutcome;
use App\Services\ChatService;

/**
 * Sprint 13, build step 5 — the one place that builds a `tool_budget_exhausted`
 * `TurnOutcome`. Extracted out of `AgentChatService::budgetOutcome()` (which
 * used it for the round/tool_call/wall_clock/malformed budgets) so a `Rule`
 * discovering a budget breach at a TOOL boundary — `SalaryLookupPostCallRule`'s
 * clarification check, alongside `Rules\ClarificationBudgetRule`'s own generic
 * pre_call check — builds the EXACT same trace shape
 * (`floor_decision.escalation_reason`, `agent.budget_exhausted.sub`), rather
 * than a second hand-copy that could drift from the original (the exact
 * "duplicated string helpers" shape flagged for a post-sprint dedupe in
 * `roadmap.md` §7 — this one gets fixed at the source instead, since step 5
 * is exactly the moment a second call site would otherwise appear).
 */
final class BudgetOutcomeFactory
{
    /** @param  array<string,mixed>  $trace */
    public static function make(array $trace, string $sub): TurnOutcome
    {
        $trace['floor_decision'] = [
            'path' => 'agent_budget',
            'outcome' => 'escalate',
            'escalation_reason' => 'tool_budget_exhausted',
            'authority_used' => [],
            'note' => "budget exhausted: {$sub}",
        ];
        // Structured, not just the free-text note above — EscalationExplainer
        // (§D.12's MATRIX) reads this to pick the sub-outcome deterministically.
        $trace['agent']['budget_exhausted'] = ['sub' => $sub];

        return new TurnOutcome('escalate', ChatService::EMPLOYEE_ESCALATION_MESSAGE, [], $trace, 'tool_budget_exhausted');
    }
}
