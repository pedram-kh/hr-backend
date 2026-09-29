<?php

namespace App\Services\Agent\Rules;

use App\Services\Agent\Rule;
use App\Services\Agent\ToolResult;
use App\Services\Agent\TurnState;
use App\Services\Agent\Verdict;

/**
 * Sprint 13, build step 5 (plan.md §B.3.2) — `reference_fact` never leaves
 * partial material once `detectTopic()` matches (classic never falls from a
 * matched fact back to prose): `escalate` forces a card exactly like
 * `salary_lookup`'s; a plain/composed `answer` forces finish (no further
 * synthesis — `ReferenceFactPath::handle()` already ran `/synthesise` +
 * `/ground` itself for the Phase 2 composed case, or skipped both for the
 * Phase 1 quote). `NO_MATERIAL` (`no_fact`) is left alone — the planner may
 * still try `convenio_search` next.
 */
final class ReferenceFactPostCallRule implements Rule
{
    public function id(): string
    {
        return 'reference_fact_post_call';
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
