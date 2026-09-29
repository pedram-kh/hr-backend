<?php

namespace App\Services\Agent;

/**
 * Sprint 13, build step 3 (plan.md §C.8, §C.10) — tools registered in FIXED
 * order ("tools in spec order" — determinism/replay, §C.10). `finalize` and
 * `escalate` are NOT registered here — {@see AgentChatService} recognizes
 * those two names as control tools before consulting the registry.
 */
final class ToolRegistry
{
    /** @var array<string,Tool> */
    private array $tools = [];

    public function register(Tool $tool): void
    {
        $this->tools[$tool->name()] = $tool;
    }

    public function has(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    public function get(string $name): Tool
    {
        return $this->tools[$name];
    }

    /** @return list<Tool> in registration order. */
    public function all(): array
    {
        return array_values($this->tools);
    }

    /** @return list<array{name:string,description:string,input_schema:array<string,mixed>}> */
    public function definitions(): array
    {
        return array_map(static fn (Tool $t) => $t->definition(), $this->all());
    }
}
