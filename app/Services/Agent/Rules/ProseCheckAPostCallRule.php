<?php

namespace App\Services\Agent\Rules;

use App\Services\Agent\Rule;
use App\Services\Agent\ToolResult;
use App\Services\Agent\TurnState;
use App\Services\Agent\Verdict;

/**
 * Sprint 13, build step 5 (plan.md §B.3.3) — shared `post_call` rule for
 * both `convenio_search` and `national_law` (identical decision either
 * way, since both tools wrap the exact same `ProsePath::handle()`, see
 * `Tools\ConvenioSearchTool`'s docblock): a `TERMINAL` result (`answer`, or
 * any escalate OTHER than a bare R16 Check-A-retrieval-floor miss) is
 * always forced. `NO_MATERIAL` (an R16-only miss — the tool's own `run()`
 * already distinguished it before returning) is left alone so the planner
 * may still try the other of the two, or `general_knowledge`.
 */
final class ProseCheckAPostCallRule implements Rule
{
    public function id(): string
    {
        return 'prose_check_a_post_call';
    }

    public function evaluate(TurnState $state, ?array $call, ?ToolResult $result): Verdict
    {
        if ($result === null || $result->status !== ToolResult::TERMINAL || $result->terminalOutcome === null) {
            return Verdict::allow();
        }

        $outcome = $result->terminalOutcome;

        if ($outcome->outcome === 'escalate') {
            return Verdict::forceEscalate($outcome, $this->id());
        }

        return Verdict::forceFinish($outcome, $this->id());
    }
}
