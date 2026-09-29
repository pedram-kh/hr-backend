<?php

namespace App\Services\Agent\Rules;

use App\Services\Agent\Rule;
use App\Services\Agent\ToolResult;
use App\Services\Agent\TurnState;
use App\Services\Agent\Verdict;

/**
 * Sprint 13, build step 5 (plan.md §B.4) — `post_call:ask_employee`. Every
 * `TERMINAL` result from `Tools\AskEmployeeTool::run()` is an `ask` — there
 * is no other outcome shape it can produce (every violation was already
 * denied/forced at `pre_call:ask_employee` before `run()` ever executed) —
 * so this rule unconditionally forces it. `TurnState::applyForced()`
 * increments `asksThisTurn` for `FORCE_ASK` — the same clarification-budget
 * bookkeeping `needs_category` uses (§B.4.3, one family).
 */
final class AskEmployeePostCallRule implements Rule
{
    public function id(): string
    {
        return 'ask_employee_post_call';
    }

    public function evaluate(TurnState $state, ?array $call, ?ToolResult $result): Verdict
    {
        if ($result === null || $result->status !== ToolResult::TERMINAL || $result->terminalOutcome === null) {
            return Verdict::allow();
        }

        return Verdict::forceAsk($result->terminalOutcome, $this->id());
    }
}
