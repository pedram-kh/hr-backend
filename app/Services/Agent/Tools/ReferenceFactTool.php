<?php

namespace App\Services\Agent\Tools;

use App\Services\Agent\Rules\CorpusMiss;
use App\Services\Agent\Tool;
use App\Services\Agent\ToolResult;
use App\Services\Agent\TurnState;
use App\Services\Answer\ReferenceFactPath;
use App\Services\GuardrailPolicy;
use App\Services\ReferenceFactRouter;
use App\Support\EscalationExplainer;

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
        private readonly GuardrailPolicy $guardrails,
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
        // Sprint 13b (plan.md §4.1): a topic from a VALIDATED planner normalization wins over the lexicon
        // (`null` topic → today's lexicon call, untouched). The planner never supplies the id through this
        // tool's own input — it arrives via `TurnState`, only after `NormalizationValidationRule` allowed it.
        $normalizedTopic = $state->normalizedTopicId();
        $detection = null;
        $lexicon = null;
        $viaNormalization = false;
        if ($normalizedTopic !== null) {
            $detection = $this->referenceFactRouter->detectFromTopic($state->employee, $normalizedTopic, $state->asOfDate);
            $viaNormalization = $detection !== null;
            $lexicon = $this->referenceFactRouter->detectTopic($state->employee, $state->question, $state->asOfDate);
        }
        if ($detection === null) {
            $detection = $this->referenceFactRouter->detectTopic($state->employee, $state->question, $state->asOfDate);
        }

        if ($detection === null) {
            return new ToolResult(ToolResult::NO_MATERIAL, plannerSummary: ['status' => 'no_fact']);
        }

        $normalization = null;
        if ($viaNormalization) {
            $normalization = [
                'topic_name' => $detection['topic_name'],
                'canonical_query' => $state->normalizedCanonical(),
                'confidence' => (float) ($state->normalization['proposed']['confidence'] ?? 0.0),
            ];
        }

        $outcome = $this->referenceFactPath->handle($state->employee, $state->question, $detection, $state->asOfDate, $state->trace, $normalization);

        if ($viaNormalization) {
            $state->recordNormalizationConsumer([
                'tool' => 'reference_fact',
                'via' => ($state->normalization['round1a']['active'] ?? false) ? 'round_1a' : 'planner_call',
                'topic_id' => $detection['topic_id'],
                'lexicon_topic_id' => $lexicon['topic_id'] ?? null,
                'disagreement' => $lexicon !== null && $lexicon['topic_id'] !== $detection['topic_id'],
                'outcome' => $outcome->outcome,
                // an ANSWER reached only because the planner's validated topic found a fact the literal lexicon could not
                'rescued_answer' => $lexicon === null && $outcome->outcome === 'answer',
            ]);
        }

        // Correction-13c-01 (ADR-0038 amendment): a coverage GAP (no group, group not approved, no sub-area, no convenio, no
        // verified data) is the corpus not having answered — an explanatory question is handed back to the planner (status
        // `no_fact`, which the planner already continues from with `convenio_search`), the escalation stashed so a `finalize` with
        // nothing else re-surfaces exactly what is escalated today. Lane off / a question the pre-screen blocks / a fact conflict
        // → terminal, byte for byte as before.
        // A composition that did not answer (verified fact + governing prose, `low_confidence`) is handed back the same way.
        if (CorpusMiss::referenceFactMayHandOver($outcome, $state->question, $this->guardrails)) {
            $isGap = CorpusMiss::referenceFactGapMayHandOver($outcome, $state->question, $this->guardrails);

            return new ToolResult(
                ToolResult::NO_MATERIAL,
                terminalOutcome: $outcome,
                traceBlocks: [
                    'reference_fact' => $outcome->trace['reference_fact'] ?? [],
                    'general_lane_precondition' => $isGap
                        ? [
                            'kind' => 'reference_fact_gap',
                            'sub_outcome' => EscalationExplainer::subOutcomeOf(CorpusMiss::REFERENCE_FACT_GAP_REASON, $outcome->trace),
                            'topic' => $detection['topic_name'],
                        ]
                        : [
                            'kind' => 'reference_fact_composition',
                            'check_b_citations' => $outcome->trace['floor_decision']['check_b_citations'] ?? null,
                            'grounded' => $outcome->trace['floor_decision']['grounding']['grounded'] ?? null,
                            'topic' => $detection['topic_name'],
                        ],
                ],
                plannerSummary: ['status' => 'no_fact'],
            );
        }

        // planner_summary (§C.10): status + topic name only — never the value.
        return new ToolResult(
            ToolResult::TERMINAL,
            terminalOutcome: $outcome,
            plannerSummary: ['status' => $outcome->outcome, 'topic' => $detection['topic_name']],
        );
    }
}
