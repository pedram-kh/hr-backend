<?php

namespace App\Services\Agent\Tools;

use App\Services\Agent\Tool;
use App\Services\Agent\ToolResult;
use App\Services\Agent\TurnState;
use App\Services\Answer\ReferenceFactPath;
use App\Services\ReferenceFactRouter;

/**
 * Sprint 13, build step 5 (plan.md §B.3.2) — thin wrapper over
 * `ReferenceFactRouter::detectTopic()` (the deterministic lexicon match, Q1/Q3
 * — never a planner-supplied topic id) + `App\Services\Answer\ReferenceFactPath
 * ::handle()`, the SAME class round 0 already calls for the deterministic
 * verified-fact short-circuit.
 *
 * Unlike `salary_lookup`, a `no_fact` result is genuinely `NO_MATERIAL` (§B.2)
 * — classic's own fall-through when `detectTopic()` returns null
 * (`ChatService.php:275-279`) — NOT an escalation, and the planner may keep
 * going (try `convenio_search` next). Every OTHER result (`answer`/`escalate`)
 * is a complete turn decision, same as salary: classic never falls from a
 * matched fact back to prose (§B.3.2's own post-call note).
 */
final class ReferenceFactTool implements Tool
{
    public function __construct(
        private readonly ReferenceFactRouter $referenceFactRouter,
        private readonly ReferenceFactPath $referenceFactPath,
    ) {}

    public function name(): string
    {
        return 'reference_fact';
    }

    public function definition(): array
    {
        return [
            'name' => 'reference_fact',
            'description' => 'Busca un dato de referencia VERIFICADO por RR. HH. para el tema de la '
                .'pregunta y el alcance de la persona (p. ej. duración del periodo de prueba por grupo). '
                .'Úsala antes que convenio_search cuando la pregunta trate de un tema con datos '
                .'verificados (ver "temas con dato verificado" en el contexto). Si responde "no_fact", '
                .'continúa con convenio_search.',
            'input_schema' => ['type' => 'object', 'properties' => [], 'additionalProperties' => false],
        ];
    }

    public function countsAsClarification(): bool
    {
        return false;
    }

    public function run(array $input, TurnState $state): ToolResult
    {
        $detection = $this->referenceFactRouter->detectTopic($state->employee, $state->question, $state->asOfDate);

        if ($detection === null) {
            return new ToolResult(ToolResult::NO_MATERIAL, plannerSummary: ['status' => 'no_fact']);
        }

        $outcome = $this->referenceFactPath->handle($state->employee, $state->question, $detection, $state->asOfDate, $state->trace);

        // planner_summary (§C.10): status + topic name only — never the value.
        return new ToolResult(
            ToolResult::TERMINAL,
            terminalOutcome: $outcome,
            plannerSummary: ['status' => $outcome->outcome, 'topic' => $detection['topic_name']],
        );
    }
}
