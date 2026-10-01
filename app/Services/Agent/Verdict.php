<?php

namespace App\Services\Agent;

use App\Services\Answer\TurnOutcome;
use App\Services\Decline\DeclineGate;

/**
 * Sprint 13, build step 3 (plan.md §B.2, §D.11) — the rule engine's answer at
 * any boundary (`turn_start`, `pre_call`, `pre_call:<tool>`, `post_call:<tool>`,
 * `finish`): `allow | rewrite(call') | deny(message_for_planner) | force(escalate|ask|finish)`.
 *
 * `force` is the ONLY status that can terminate the loop outside the
 * planner's own `finalize`/`escalate` calls — {@see AgentChatService} sets
 * `TurnState::$terminated = true` the instant one is returned and discards
 * every other pending call in that round (`skipped_after_forced_verdict`).
 * This is what makes "the planner cannot suppress a forced escalation" a
 * structural guarantee rather than a prompting convention (§D.11 tests).
 */
final class Verdict
{
    public const ALLOW = 'allow';

    public const REWRITE = 'rewrite';

    public const DENY = 'deny';

    public const FORCE = 'force';

    public const FORCE_ESCALATE = 'escalate';

    public const FORCE_ASK = 'ask';

    public const FORCE_FINISH = 'finish';

    /** Slice 13e — a confirmed off-domain question ({@see DeclineGate}); the payload is a `decline` outcome. */
    public const FORCE_DECLINE = 'decline';

    private function __construct(
        public readonly string $status,
        public readonly ?string $rule = null,
        /** @var array<string,mixed>|null */
        public readonly ?array $rewrittenCall = null,
        public readonly ?string $denialMessage = null,
        public readonly ?string $forceType = null,
        public readonly ?TurnOutcome $forcePayload = null,
    ) {}

    public static function allow(): self
    {
        return new self(self::ALLOW);
    }

    /** @param  array<string,mixed>  $call */
    public static function rewrite(array $call, string $rule): self
    {
        return new self(self::REWRITE, $rule, rewrittenCall: $call);
    }

    public static function deny(string $message, string $rule): self
    {
        return new self(self::DENY, $rule, denialMessage: $message);
    }

    public static function forceEscalate(TurnOutcome $outcome, string $rule): self
    {
        return new self(self::FORCE, $rule, forceType: self::FORCE_ESCALATE, forcePayload: $outcome);
    }

    /**
     * Sprint 13, build step 5 (plan.md §B.4.3) — `$outcome->outcome` is
     * `'ask'` (`ask_employee`'s own clarifying question) or `'needs_category'`
     * (`salary_lookup`'s constrained pick, §B.3.1's post-call rule). The plan
     * groups both under ONE clarification budget ("the session's assistant
     * messages whose `trace.floor_decision.outcome ∈ {ask, needs_category}`",
     * §B.4.3) — generalized here to one force type rather than two, since
     * `TurnState`'s counter already treats them as one family.
     */
    public static function forceAsk(TurnOutcome $outcome, string $rule): self
    {
        return new self(self::FORCE, $rule, forceType: self::FORCE_ASK, forcePayload: $outcome);
    }

    /**
     * Sprint 13, build step 5 — "the tool already fully decided this turn
     * with a non-escalation, non-ask outcome" (e.g. `salary_lookup`'s plain
     * `answer`: a quoted figure, nothing to synthesize). Distinct from
     * `AgentChatService::finish()` (the general prose/composition finisher,
     * which runs on an explicit planner `finalize` with no forced verdict) —
     * this bypasses that machinery entirely.
     */
    public static function forceFinish(TurnOutcome $outcome, string $rule): self
    {
        return new self(self::FORCE, $rule, forceType: self::FORCE_FINISH, forcePayload: $outcome);
    }

    /** Slice 13e — `$outcome->outcome` must be `'decline'`, which only the decline factory on TurnOutcome can produce. */
    public static function forceDecline(TurnOutcome $outcome, string $rule): self
    {
        if ($outcome->outcome !== 'decline') {
            throw new \InvalidArgumentException('forceDecline needs a decline outcome.');
        }

        return new self(self::FORCE, $rule, forceType: self::FORCE_DECLINE, forcePayload: $outcome);
    }

    public function isAllow(): bool
    {
        return $this->status === self::ALLOW;
    }

    public function isTerminal(): bool
    {
        return $this->status === self::FORCE;
    }
}
