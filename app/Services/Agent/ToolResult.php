<?php

namespace App\Services\Agent;

use App\Services\Answer\TurnOutcome;

/**
 * Sprint 13, build step 3 (plan.md §B.2) — what `Tool::run()` returns.
 *
 * - `MATERIAL`: the tool produced something the eventual finisher can use
 *   (e.g. `convenio_search` chunks that cleared Check A) but did not decide
 *   the turn's outcome by itself.
 * - `NO_MATERIAL`: the tool deliberately found nothing to route on (e.g.
 *   `reference_fact`'s `no_fact` — mirrors classic's fall-through,
 *   `ChatService.php:275-279`) — NOT an escalation; the planner may continue.
 * - `TERMINAL`: the tool itself already decided the turn (a `TurnOutcome` in
 *   `$terminalOutcome`) — e.g. `salary_lookup` returning a coverage gap. A
 *   `post_call:<tool>` rule inspects this and decides whether to force it
 *   (§B.3.1's R09: "no other tool may produce a salary figure after a salary
 *   gap" is exactly this — the FORCE is the rule's decision, not automatic,
 *   so a tool can legitimately return `TERMINAL` material a rule chooses not
 *   to force in some other, future tool shape).
 */
final class ToolResult
{
    public const MATERIAL = 'material';

    public const NO_MATERIAL = 'no_material';

    public const TERMINAL = 'terminal';

    /**
     * @param  array<string,mixed>|null  $material
     * @param  array<string,mixed>  $traceBlocks
     * @param  array<string,mixed>  $plannerSummary
     */
    public function __construct(
        public readonly string $status,
        public readonly ?array $material = null,
        public readonly ?TurnOutcome $terminalOutcome = null,
        public readonly array $traceBlocks = [],
        public readonly array $plannerSummary = [],
    ) {}
}
