<?php

namespace App\Services\Agent\Rules;

use App\Services\Agent\Rule;
use App\Services\Agent\ToolResult;
use App\Services\Agent\TurnState;
use App\Services\Agent\Verdict;
use App\Services\Answer\TurnOutcome;
use App\Services\ChatService;
use App\Services\Decline\DeclineGate;
use App\Services\Decline\DeclineGrant;

/**
 * Slice 13e (plan.md §4.3, ADR-0039) — `pre_call:escalate`. The planner called `escalate{category: off_domain}`; this is
 * the only place that can turn that into a DECLINE (no escalation card) instead of today's `planner_escalated` card.
 *
 * It decides nothing itself: `AgentChatService` gathered the facts (including the one router confirmation) into
 * `TurnState::$declineFacts`, and {@see DeclineGate} is a pure function of them. A denied decision returns `allow`, so the
 * existing escalation path runs exactly as it did before this slice — "more escalation, never less" — with the gate's
 * evidence left on `state->declineDecision` for the trace.
 */
final class OffDomainDeclineRule implements Rule
{
    public function id(): string
    {
        return 'off_domain_decline';
    }

    public function evaluate(TurnState $state, ?array $call, ?ToolResult $result): Verdict
    {
        $facts = $state->declineFacts;
        if ($facts === null) {
            return Verdict::allow();
        }

        $decision = DeclineGate::decide($facts);
        $state->declineDecision = $decision;
        if (! $decision->granted) {
            return Verdict::allow();
        }

        $trace = $state->trace;
        $trace['floor_decision'] = [
            'path' => 'agent_planner',
            'outcome' => 'decline',
            'decline_reason' => DeclineGate::ONLY_REASON,
            'authority_used' => [],
            'note' => 'planner off_domain confirmed by the decline gate — declined, no escalation card',
        ];
        $trace['decline'] = $decision->toTrace();

        return Verdict::forceDecline(
            TurnOutcome::decline(DeclineGrant::fromDecision($decision), ChatService::DECLINE_MESSAGE, $trace),
            $this->id(),
        );
    }
}
