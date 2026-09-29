<?php

namespace App\Services\Agent\Rules;

use App\Services\Agent\Rule;
use App\Services\Agent\ToolResult;
use App\Services\Agent\TurnState;
use App\Services\Agent\Verdict;

/**
 * Sprint 13, build step 9 (plan.md §B.6) — the SECOND `post_call:
 * general_knowledge` rule, registered AFTER {@see GeneralLanePostCheck}
 * (`RuleEngine::run()` stops at the first non-allow verdict, so the post-
 * check always gets first look at a candidate `answer` and may still
 * discard it before this rule ever runs). Whatever survives — a clean
 * `answer`, or a `GeneralKnowledgeTool`-decided `escalate` (ungrounded/no web
 * source already turned into `NO_MATERIAL` upstream, so only a genuine
 * escalate reaches here) — is forced, exactly like `ProseCheckAPostCallRule`
 * does for `convenio_search`/`national_law`'s own TERMINAL results.
 */
final class GeneralLaneFinishRule implements Rule
{
    public function id(): string
    {
        return 'general_lane_finish';
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
