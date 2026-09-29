<?php

namespace App\Services\Agent\Rules;

use App\Services\Agent\Rule;
use App\Services\Agent\ToolResult;
use App\Services\Agent\TurnState;
use App\Services\Agent\Verdict;
use App\Services\RouterService;

/**
 * Sprint 13, build step 5 (plan.md §B.3.2 "Pre-call rules: not on a salary
 * question (Q4 precedence)") — mirrors round 0's own ordering
 * (`AgentChatService::runRoundZero()`: the reference-fact branch only runs
 * `if (! matchesSalary($question))`) so the planner cannot bypass Q4's
 * salary-first precedence by calling `reference_fact` directly on a salary
 * question. A denial (not a force) — the planner just gets told to use
 * `salary_lookup` instead and tries again, costing a round (§D.11).
 */
final class ReferenceFactSalaryPrecedenceRule implements Rule
{
    public function __construct(private readonly RouterService $router) {}

    public function id(): string
    {
        return 'reference_fact_salary_precedence';
    }

    public function evaluate(TurnState $state, ?array $call, ?ToolResult $result): Verdict
    {
        if ($this->router->matchesSalary($state->question)) {
            return Verdict::deny('Esta pregunta es de salario — usa salary_lookup, no reference_fact.', $this->id());
        }

        return Verdict::allow();
    }
}
