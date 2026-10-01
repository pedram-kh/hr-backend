<?php

namespace App\Services\Decline;

use App\Services\Answer\TurnOutcome;
use LogicException;

/**
 * Slice 13e — the receipt {@see TurnOutcome::decline()} demands. It can be made from a granted
 * {@see DeclineDecision} for reason `off_domain` and from nothing else. (PHP cannot make a token unforgeable; this is the
 * funnel — L1 in plan.md §4.2. L2 re-checks the trace in the persister, L3/L4 are tests.)
 */
final class DeclineGrant
{
    private function __construct(public readonly string $source) {}

    public static function fromDecision(DeclineDecision $decision): self
    {
        if (! $decision->granted || $decision->reason !== DeclineGate::ONLY_REASON) {
            throw new LogicException('A decline needs a granted off_domain decision.');
        }

        return new self($decision->source);
    }
}
