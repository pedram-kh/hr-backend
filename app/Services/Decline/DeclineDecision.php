<?php

namespace App\Services\Decline;

/**
 * Slice 13e — the gate's answer. Built only by {@see DeclineGate::decide()} (`fromGate()` is @internal; the architecture test
 * `DeclineStructuralTest` fails if any other file calls it). `toTrace()` is the `trace.decline` block the persister re-checks.
 */
final class DeclineDecision
{
    /**
     * @param  list<array{id:string,pass:bool,detail:?string}>  $checks
     * @param  ?array<string,mixed>  $confirm
     * @param  array<string,mixed>  $context
     */
    private function __construct(
        public readonly bool $granted,
        public readonly string $source,
        public readonly string $reason,
        public readonly array $checks,
        public readonly ?string $deniedBy,
        public readonly ?array $confirm,
        public readonly array $context,
    ) {}

    /**
     * @internal only DeclineGate::decide() may call this.
     *
     * @param  list<array{id:string,pass:bool,detail:?string}>  $checks
     * @param  ?array<string,mixed>  $confirm
     * @param  array<string,mixed>  $context
     */
    public static function fromGate(bool $granted, string $source, string $reason, array $checks, ?string $deniedBy, ?array $confirm, array $context): self
    {
        return new self($granted, $source, $reason, $checks, $deniedBy, $confirm, $context);
    }

    /** @return list<string> ids of the checks that failed */
    public function failedIds(): array
    {
        return array_values(array_map(fn ($c) => $c['id'], array_filter($this->checks, fn ($c) => ! $c['pass'])));
    }

    /** @return array<string,mixed> the `trace.decline` block */
    public function toTrace(): array
    {
        return [
            'granted' => $this->granted,
            'source' => $this->source,
            'reason' => $this->reason,
            'checks' => $this->checks,
            'denied_by' => $this->deniedBy,
            'confirm' => $this->confirm,
            'gate_version' => DeclineGate::VERSION,
        ] + $this->context;
    }
}
