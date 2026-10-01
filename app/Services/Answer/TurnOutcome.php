<?php

namespace App\Services\Answer;

use App\Services\Decline\DeclineGate;
use App\Services\Decline\DeclineGrant;
use InvalidArgumentException;

/**
 * Sprint 13, build step 1 (plan.md §B.1) — the value object every path returns
 * instead of directly persisting. `ChatService::handleMessage()` and each
 * extracted `*Path` class build one of these; `TurnPersister` is the single
 * place that turns it into DB rows + the response payload.
 *
 * Fields mirror exactly what `ChatService::persistTurn()` used to take as
 * loose parameters (`$answer, $citations, $trace, $outcome, $escalationReason,
 * $categories`) — this is a pure extraction, not a redesign; no field means
 * anything new.
 */
final class TurnOutcome
{
    /**
     * Every outcome a turn can have. Pinned by a test, so adding one fails until the test (and every consumer) is edited.
     * `ask` is the agent engine's clarifying question (Sprint 13); `decline` (Slice 13e) is a confirmed off-domain question.
     */
    public const OUTCOMES = ['answer', 'escalate', 'needs_category', 'ask', 'decline'];

    /**
     * @param  string  $outcome  one of {@see self::OUTCOMES}. `decline` can only be built through {@see self::decline()}
     *                           (Slice 13e, plan.md §4.2 L1): this constructor throws for it without a grant.
     * @param  list<array<string,mixed>>  $citations
     * @param  array<string,mixed>  $trace
     * @param  list<array<string,mixed>>  $categories
     */
    public function __construct(
        public readonly string $outcome,
        public readonly string $answer,
        public readonly array $citations,
        public readonly array $trace,
        public readonly ?string $escalationReason,
        public readonly array $categories = [],
        public readonly ?DeclineGrant $declineGrant = null,
    ) {
        if (! in_array($outcome, self::OUTCOMES, true)) {
            throw new InvalidArgumentException("Unknown turn outcome '{$outcome}'.");
        }
        if ($outcome === 'decline' && $declineGrant === null) {
            throw new InvalidArgumentException('A decline needs a DeclineGrant — build it with TurnOutcome::decline().');
        }
        if ($outcome !== 'decline' && $declineGrant !== null) {
            throw new InvalidArgumentException('A DeclineGrant belongs to a decline outcome only.');
        }
    }

    /**
     * The ONLY way to build a decline (Slice 13e). The grant can only come from a granted `off_domain` decision of
     * {@see DeclineGate}; a decline has no escalation reason and no citations.
     *
     * @param  array<string,mixed>  $trace
     */
    public static function decline(DeclineGrant $grant, string $answer, array $trace): self
    {
        return new self('decline', $answer, [], $trace, null, [], $grant);
    }

    /** A copy with a different trace (same outcome, same grant). @param  array<string,mixed>  $trace */
    public function withTrace(array $trace): self
    {
        return new self($this->outcome, $this->answer, $this->citations, $trace, $this->escalationReason, $this->categories, $this->declineGrant);
    }
}
