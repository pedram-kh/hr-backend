<?php

namespace App\Services\Decline;

/**
 * Slice 13e — everything {@see DeclineGate} needs, as plain values. Built by {@see DeclineFactsCollector}
 * (the only code that touches the database, the router or the turn state); the gate itself is a pure function of this.
 */
final class DeclineFacts
{
    public const SOURCE_PLANNER = 'planner';

    public const SOURCE_GUARD_ADMIN = 'guard_admin';

    /**
     * @param  string  $source  planner | guard_admin
     * @param  string  $reason  the planner's `category` or the guard's `reason` — only `off_domain` can ever pass (D1)
     * @param  ?string  $vetoWord  D7 — the workplace word that fired, or null
     * @param  ?string  $guardRule  `guardrail_check.rule` for the guard source (D9)
     * @param  ?array{label:string,confidence:float,floor:float,source:?string}  $confirm  D8 — the independent router vote, null = not consulted
     * @param  array<string,mixed>  $context  trace-only detail (matched pattern, planner reason); never read by the gate
     */
    public function __construct(
        public readonly bool $enabled,
        public readonly string $source,
        public readonly string $reason,
        public readonly bool $baselineGuardHit,
        public readonly bool $adminSensitiveHit,
        public readonly bool $explicitRequest,
        public readonly bool $acceptedNormalization,
        public readonly bool $corpusMaterial,
        public readonly bool $pendingAsk,
        public readonly ?string $vetoWord,
        public readonly ?string $guardRule,
        public readonly ?array $confirm,
        public readonly array $context = [],
    ) {}

    /** @param  array{label:string,confidence:float,floor:float,source:?string}  $confirm */
    public function withConfirm(array $confirm): self
    {
        return new self(
            $this->enabled, $this->source, $this->reason, $this->baselineGuardHit, $this->adminSensitiveHit,
            $this->explicitRequest, $this->acceptedNormalization, $this->corpusMaterial, $this->pendingAsk,
            $this->vetoWord, $this->guardRule, $confirm, $this->context,
        );
    }
}
