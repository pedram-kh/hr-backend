<?php

namespace App\Services\Agent\Tools;

use App\Models\AnswerModelSetting;
use App\Services\Agent\PiiScrubber;
use App\Services\Agent\Rules\GeneralLaneAvailabilityRule;
use App\Services\Agent\Rules\GeneralLaneFinishRule;
use App\Services\Agent\Rules\GeneralLanePostCheck;
use App\Services\Agent\Tool;
use App\Services\Agent\ToolResult;
use App\Services\Agent\TurnState;
use App\Services\Answer\TurnOutcome;
use App\Services\ChatService;
use App\Services\ExtractionClient;

/**
 * Sprint 13, build step 9 (plan.md §B.6) — `general_knowledge`. Only reached
 * after `pre_call:general_knowledge` ({@see GeneralLaneAvailabilityRule})
 * has already confirmed the lane is enabled, `convenio_search`/`national_law`
 * missed Check A this turn, and the question itself isn't a figure/entitlement
 * ask (the pre-screen). `post_call:general_knowledge` then runs
 * {@see GeneralLanePostCheck} (discards + force-
 * escalates on ANY figure/entitlement pattern) and
 * {@see GeneralLaneFinishRule} (forces finish/
 * escalate on whatever survives) — this tool itself never terminates the
 * turn; it only ever returns `NO_MATERIAL` or `TERMINAL` for those rules to
 * act on, same division of labour as `ConvenioSearchTool`.
 *
 * §F.8 (grounding tension, §B.6.4): an answer with NO fetched source has
 * nothing to entail against `/ground`, and the plan's own recommendation is
 * to allow that ONLY if CP-2's negative-question set shows zero lane leaks
 * over 3 repeats — unmeasured at this point in the build (CP-2 has not run).
 * Per the standing "no sign-off → the plan's own documented safe default"
 * posture (§F.2/§F.15's precedent), v1 here is WEB-SOURCED ONLY: a
 * `sources=[{kind:'model_knowledge'}]` response (no fetched excerpt at all)
 * is treated as `NO_MATERIAL`, never surfaced, never forced — the planner is
 * free to try something else or, more likely, call `escalate` itself.
 * Flagged in review.md for a decision before CP-1, exactly like §F.15.
 */
final class GeneralKnowledgeTool implements Tool
{
    public function __construct(
        private readonly ExtractionClient $ai,
        private readonly PiiScrubber $scrubber,
    ) {}

    public function name(): string
    {
        return 'general_knowledge';
    }

    public function definition(): array
    {
        return [
            'name' => 'general_knowledge',
            'description' => 'NO DISPONIBLE al empezar el turno: nunca la propongas como primera herramienta, '
                .'aunque la pregunta sea conceptual (qué es una excedencia, qué significa IT). Empieza '
                .'siempre por convenio_search (o reference_fact / salary_lookup si corresponde). Solo '
                .'puedes usarla después de que convenio_search no encuentre material '
                .'o su respuesta no llegue a sustentarse (status check_a_failed / entailment_failed). '
                .'Nunca para cantidades, plazos, porcentajes ni derechos concretos de la persona: esas '
                .'preguntas se derivan. La respuesta se muestra marcada como información general.',
            'input_schema' => ['type' => 'object', 'properties' => [], 'additionalProperties' => false],
        ];
    }

    public function countsAsClarification(): bool
    {
        return false;
    }

    public function run(array $input, TurnState $state): ToolResult
    {
        $scrub = $this->scrubber->scrub($state->employee, $state->question);

        $settings = AnswerModelSetting::current();
        if (! $settings->isConfigured()) {
            return new ToolResult(ToolResult::NO_MATERIAL, plannerSummary: ['status' => 'unavailable']);
        }

        $config = config('hr.general_lane');
        $decryptedKey = $settings->decryptKey();
        $raw = $this->ai->generalKnowledge(
            $scrub['text'],
            $config['sources'] ?? [],
            $config['domains'] ?? [],
            $decryptedKey,
            [
                'provider' => config('services.hr_ai.answer_provider', 'claude'),
                'model' => config('services.hr_ai.answer_model'),
                'endpoint' => config('services.hr_ai.answer_endpoint'),
            ],
        );
        unset($decryptedKey);

        if (isset($raw['error']) || ! is_string($raw['answer'] ?? null) || $raw['answer'] === '') {
            return new ToolResult(ToolResult::NO_MATERIAL, plannerSummary: ['status' => 'unavailable']);
        }

        $sources = is_array($raw['sources'] ?? null) ? $raw['sources'] : [];
        $webSources = array_values(array_filter($sources, fn ($s) => is_array($s) && ($s['kind'] ?? null) === 'web'));

        if ($webSources === []) {
            // Model-knowledge-only — v1 default is web-sourced-only (§F.8, no
            // sign-off), see class docblock. Not a gap: an honest NO_MATERIAL.
            return new ToolResult(ToolResult::NO_MATERIAL, plannerSummary: ['status' => 'no_web_source']);
        }

        $fetches = is_array($raw['trace_fragment']['fetches'] ?? null) ? $raw['trace_fragment']['fetches'] : [];
        // `excerpt` (the raw fetched page text) is used for grounding ONLY —
        // never persisted to the trace (§B.6.6's `general_lane` shape is
        // {sources:[{kind,id|title,url?}], ...}, not a page-text dump).
        $tracedSources = array_map(fn (array $s) => array_diff_key($s, ['excerpt' => null]), $sources);
        $generalLaneTrace = [
            'question_scrubbed' => $scrub['text'],
            'scrub' => ['kinds' => $scrub['kinds'], 'count' => $scrub['count']],
            'sources' => $tracedSources,
            'fetches' => $fetches,
        ];

        $groundChunks = array_map(fn (array $s) => [
            'chunk_id' => null,
            'source_type' => 'general_web',
            'content' => (string) ($s['excerpt'] ?? ''),
            'authority_level' => null,
            'is_tabular' => false,
        ], $webSources);

        $groundDecryptedKey = $settings->decryptKey();
        $grounding = $this->ai->ground($state->question, $raw['answer'], $groundChunks, $groundDecryptedKey, [
            'provider' => config('services.hr_ai.answer_provider', 'claude'),
            'model' => config('services.hr_ai.answer_model'),
            'endpoint' => config('services.hr_ai.answer_endpoint'),
        ]);
        unset($groundDecryptedKey);

        if (isset($grounding['error']) || ($grounding['grounded'] ?? false) !== true) {
            $generalLaneTrace['grounding'] = ['checked' => true, 'grounded' => false];
            $trace = $state->trace;
            $trace['floor_decision'] = [
                'path' => 'general_knowledge',
                'outcome' => 'escalate',
                'escalation_reason' => 'low_confidence',
                'authority_used' => [],
                'note' => 'general lane answer failed grounding against its fetched source',
            ];
            $trace['general_lane'] = $generalLaneTrace;
            $outcome = new TurnOutcome('escalate', ChatService::EMPLOYEE_ESCALATION_MESSAGE, [], $trace, 'low_confidence');

            return new ToolResult(ToolResult::TERMINAL, terminalOutcome: $outcome, plannerSummary: ['status' => 'escalate']);
        }

        $generalLaneTrace['grounding'] = ['checked' => true, 'grounded' => true];
        $trace = $state->trace;
        $trace['floor_decision'] = [
            'path' => 'general_knowledge',
            'outcome' => 'answer',
            'authority_used' => ['general_knowledge'],
        ];
        $trace['general_lane'] = $generalLaneTrace;
        $outcome = new TurnOutcome('answer', $raw['answer'], [], $trace, null);

        // NOT forced here — `GeneralLanePostCheck` (post_call:general_knowledge)
        // must see this TERMINAL/answer result FIRST and may still discard it.
        return new ToolResult(ToolResult::TERMINAL, terminalOutcome: $outcome, plannerSummary: ['status' => 'answer']);
    }
}
