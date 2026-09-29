<?php

namespace App\Services\Agent\Rules;

use App\Services\Agent\BudgetOutcomeFactory;
use App\Services\Agent\Rule;
use App\Services\Agent\Tool;
use App\Services\Agent\ToolRegistry;
use App\Services\Agent\ToolResult;
use App\Services\Agent\TurnState;
use App\Services\Agent\Verdict;

/**
 * Sprint 13, build step 3 (plan.md §B.4.3, §C.9) — the 2-per-conversation
 * clarification budget. Generic (registered at the `pre_call` boundary, runs
 * before EVERY tool call), rather than hand-wired into `ask_employee` alone —
 * §B.4.3's own note that "this also bounds the salary pick in the agent
 * engine" implies more than one tool can count as a clarification
 * ({@see Tool::countsAsClarification()}), so the budget
 * check has to be tool-agnostic too.
 */
final class ClarificationBudgetRule implements Rule
{
    public function __construct(private readonly ToolRegistry $tools) {}

    public function id(): string
    {
        return 'budget_clarifications';
    }

    public function evaluate(TurnState $state, ?array $call, ?ToolResult $result): Verdict
    {
        $toolName = $call['tool'] ?? null;
        if (! is_string($toolName) || ! $this->tools->has($toolName)) {
            return Verdict::allow();
        }
        if (! $this->tools->get($toolName)->countsAsClarification()) {
            return Verdict::allow();
        }
        if ($state->clarificationsUsed() < 2) {
            return Verdict::allow();
        }

        return Verdict::forceEscalate(BudgetOutcomeFactory::make($state->trace, 'clarifications'), $this->id());
    }
}
