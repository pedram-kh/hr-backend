<?php

namespace App\Services\Answer;

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
     * @param  string  $outcome  'answer' | 'escalate' | 'needs_category'
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
    ) {}
}
