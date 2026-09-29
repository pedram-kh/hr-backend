<?php

namespace App\Services\Agent;

/**
 * Sprint 13, build step 3 (plan.md §D.11) — one rule, registered against one
 * boundary (`turn_start`, `pre_call`, `pre_call:<tool>`, `post_call:<tool>`,
 * `finish`) in {@see RuleEngine}. `$call` is present at `pre_call*` boundaries
 * (the planner's raw tool call, `{id, tool, input}`); `$result` is present at
 * `post_call:<tool>` (the {@see ToolResult} the tool just returned). Both are
 * `null` at `turn_start`/`finish`.
 */
interface Rule
{
    /** Stable id, recorded on the trace's `rule_verdict` step (e.g. `R08`, `national_law_rewrite`). */
    public function id(): string;

    /** @param  array<string,mixed>|null  $call */
    public function evaluate(TurnState $state, ?array $call, ?ToolResult $result): Verdict;
}
