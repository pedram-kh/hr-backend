<?php

namespace App\Services\Agent\Rules;

use App\Services\Agent\BudgetOutcomeFactory;
use App\Services\Agent\Rule;
use App\Services\Agent\ToolResult;
use App\Services\Agent\TurnState;
use App\Services\Agent\Verdict;

/**
 * Sprint 13, build step 5 (plan.md §B.3.1) — `salary_lookup` never leaves
 * partial material (ADR-0006/0027: a salary figure is never read from
 * prose), so every `ToolResult::TERMINAL` it produces is forced here, one of
 * three ways:
 * - `escalate` (coverage gap, cross-path, SMI/statutory — all already
 *   decided INSIDE `SalaryPath::handle()`, unchanged) → forced escalation.
 * - `needs_category` → the clarification family (§B.4.3): if the budget is
 *   already spent, this is the THIRD clarification attempt for THIS
 *   conversation and forces `tool_budget_exhausted`/`clarifications`
 *   instead of the pick — a real bound classic's own unbounded pick never
 *   had (§B.4.3's own note: "classic's pick stays unbounded").
 * - plain `answer` → forced finish; a quoted figure needs no synthesis.
 */
final class SalaryLookupPostCallRule implements Rule
{
    public function id(): string
    {
        return 'salary_lookup_post_call';
    }

    public function evaluate(TurnState $state, ?array $call, ?ToolResult $result): Verdict
    {
        if ($result === null || $result->status !== ToolResult::TERMINAL || $result->terminalOutcome === null) {
            return Verdict::allow();
        }

        $outcome = $result->terminalOutcome;

        if ($outcome->outcome === 'needs_category') {
            if ($state->clarificationsUsed() >= 2) {
                return Verdict::forceEscalate(BudgetOutcomeFactory::make($state->trace, 'clarifications'), $this->id());
            }

            return Verdict::forceAsk($outcome, $this->id());
        }

        if ($outcome->outcome === 'escalate') {
            return Verdict::forceEscalate($outcome, $this->id());
        }

        return Verdict::forceFinish($outcome, $this->id());
    }
}
