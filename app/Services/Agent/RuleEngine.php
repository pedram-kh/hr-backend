<?php

namespace App\Services\Agent;

/**
 * Sprint 13, build step 3 (plan.md §D.11) — a list of {@see Rule} objects
 * registered per boundary, in registration order. The FIRST non-allow
 * verdict at a boundary wins and short-circuits the rest — mirrors classic's
 * own "first matching guard fires" structure (`GuardrailService::check()`),
 * just made a registry instead of a fixed `if` chain, so the agent engine
 * can share the same "one place rules live" property classic already has.
 *
 * Boundary naming: `turn_start`, `pre_call` (generic — runs for EVERY tool
 * call, before the tool-specific one; this is where the budget rules live,
 * §C.9), `pre_call:<tool>`, `post_call:<tool>`, `finish`.
 */
final class RuleEngine
{
    /** @var array<string,list<Rule>> */
    private array $rules = [];

    public function register(string $boundary, Rule $rule): void
    {
        $this->rules[$boundary][] = $rule;
    }

    /** @param  array<string,mixed>|null  $call */
    public function run(string $boundary, TurnState $state, ?array $call = null, ?ToolResult $result = null): Verdict
    {
        foreach ($this->rules[$boundary] ?? [] as $rule) {
            $verdict = $rule->evaluate($state, $call, $result);
            if (! $verdict->isAllow()) {
                $state->recordStep([
                    'type' => 'rule_verdict',
                    'rule' => $rule->id(),
                    'verdict' => $verdict->status === Verdict::FORCE
                        ? 'force_'.$verdict->forceType
                        : $verdict->status,
                ]);

                return $verdict;
            }
        }

        return Verdict::allow();
    }
}
