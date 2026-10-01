<?php

namespace App\Services\Agent\Tools;

use App\Models\AnswerModelSetting;
use App\Services\Agent\PiiScrubber;
use App\Services\Agent\Rules\GeneralLaneAvailabilityRule;
use App\Services\Agent\Rules\GeneralLaneFinishRule;
use App\Services\Agent\Rules\GeneralLanePostCheck;
use App\Services\Agent\Rules\ModelKnowledgeShapeCheck;
use App\Services\Agent\Tool;
use App\Services\Agent\ToolResult;
use App\Services\Agent\TurnState;
use App\Services\Answer\TurnOutcome;
use App\Services\ChatService;
use App\Services\ExtractionClient;
use App\Services\GeneralLaneCatalogue;
use App\Services\GuardrailPolicy;

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
 *
 * Slice 13c (plan.md §2): with the model-knowledge sub-flag effectively on
 * ({@see GuardrailPolicy::generalLaneModelKnowledgeEnabled()}) a response with NO fetched source is no longer
 * discarded: it becomes a `basis = model_knowledge` answer — no `/ground` call (nothing to ground against), no citation
 * row, and the shape check ({@see ModelKnowledgeShapeCheck}) runs next to the post-check. Sub-flag off = the v1
 * `no_web_source` NO_MATERIAL above, byte for byte. A catalogue page that matched and yielded an excerpt still takes the
 * web-grounded path first (plan Q2).
 */
final class GeneralKnowledgeTool implements Tool
{
    public function __construct(
        private readonly ExtractionClient $ai,
        private readonly PiiScrubber $scrubber,
        private readonly GuardrailPolicy $guardrails,
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
                .'puedes usarla después de que convenio_search no encuentre material, '
                .'su respuesta no llegue a sustentarse o no pueda responder '
                .'(status check_a_failed / entailment_failed / abstained). En ese caso, si la pregunta es de '
                .'definición o funcionamiento (qué es, cómo funciona, en qué se diferencia), llámala en vez de '
                .'escalate. Nunca para cantidades, plazos, porcentajes ni derechos concretos de la persona: esas '
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

        $modelKnowledge = $this->guardrails->generalLaneModelKnowledgeEnabled();
        $raw = $this->draft($settings, $scrub['text'], $modelKnowledge, false);

        if (isset($raw['error']) || ! is_string($raw['answer'] ?? null)) {
            return new ToolResult(ToolResult::NO_MATERIAL, plannerSummary: ['status' => 'unavailable']);
        }

        $fragment = is_array($raw['trace_fragment'] ?? null) ? $raw['trace_fragment'] : [];
        $fetches = is_array($fragment['fetches'] ?? null) ? $fragment['fetches'] : [];
        // hr-ai labels a call `web` when at least one catalogue page yielded an excerpt (whatever the model then made of it).
        $webWasTried = ($fragment['basis'] ?? null) === ModelKnowledgeShapeCheck::BASIS_WEB;

        if ($raw['answer'] === '') {
            // 13c: a matched page that the model could not use (an empty draft) must not kill the answer — web first, then model.
            if ($modelKnowledge && $webWasTried) {
                return $this->modelFallback($state, $scrub, $settings, $raw, 'web_empty_draft', new ToolResult(ToolResult::NO_MATERIAL, plannerSummary: ['status' => 'unavailable']));
            }

            return new ToolResult(ToolResult::NO_MATERIAL, plannerSummary: ['status' => 'unavailable']);
        }

        $sources = is_array($raw['sources'] ?? null) ? $raw['sources'] : [];
        $webSources = array_values(array_filter($sources, fn ($s) => is_array($s) && ($s['kind'] ?? null) === 'web'));

        if ($webSources === [] && ! $modelKnowledge) {
            // Model-knowledge-only — v1 default is web-sourced-only (§F.8, no
            // sign-off), see class docblock. Not a gap: an honest NO_MATERIAL.
            return new ToolResult(ToolResult::NO_MATERIAL, plannerSummary: ['status' => 'no_web_source']);
        }

        if ($webSources === []) {
            return $this->modelResult($state, $scrub, $raw, null);
        }

        // `excerpt` (the raw fetched page text) is used for grounding ONLY —
        // never persisted to the trace (§B.6.6's `general_lane` shape is
        // {sources:[{kind,id|title,url?}], ...}, not a page-text dump).
        $tracedSources = array_map(fn (array $s) => array_diff_key($s, ['excerpt' => null]), $sources);
        $generalLaneTrace = [
            'basis' => ModelKnowledgeShapeCheck::BASIS_WEB,
            'question_scrubbed' => $scrub['text'],
            'scrub' => ['kinds' => $scrub['kinds'], 'count' => $scrub['count']],
            'sources' => $tracedSources,
            'fetches' => $fetches,
            'web_attempted' => $fetches !== [],
            'fetch_errors' => $this->fetchErrors($fetches),
            'prompt_sha256' => $fragment['prompt_sha256'] ?? null,
            'word_count' => ModelKnowledgeShapeCheck::wordCount($raw['answer']),
            // the draft call's own cost/latency (list-price, from hr-ai), so the harness and the trace panel can report them
            'draft' => array_intersect_key($fragment, array_flip(['model', 'general_knowledge_ms', 'prompt_tokens', 'completion_tokens', 'cost_usd'])),
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
            $ungrounded = new ToolResult(ToolResult::TERMINAL, terminalOutcome: $outcome, plannerSummary: ['status' => 'escalate']);

            // 13c: a web draft its own page does not support falls back to the model-knowledge draft (which the same post-check
            // and shape check then judge); if that fails too, the Sprint-13 escalation above stands.
            return $modelKnowledge
                ? $this->modelFallback($state, $scrub, $settings, $raw, 'web_ungrounded', $ungrounded)
                : $ungrounded;
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

    /** @return array<string,mixed> the /general-knowledge envelope (or an `error` one) */
    private function draft(AnswerModelSetting $settings, string $question, bool $modelKnowledge, bool $skipWeb): array
    {
        $decryptedKey = $settings->decryptKey();
        $raw = $this->ai->generalKnowledge(
            $question,
            GeneralLaneCatalogue::pages(),
            GeneralLaneCatalogue::domains(),
            $decryptedKey,
            [
                'provider' => config('services.hr_ai.answer_provider', 'claude'),
                'model' => config('services.hr_ai.answer_model'),
                'endpoint' => config('services.hr_ai.answer_endpoint'),
            ],
            $modelKnowledge,
            $skipWeb,
        );
        unset($decryptedKey);

        return $raw;
    }

    /**
     * @param  list<array<string,mixed>>  $fetches
     * @return list<array{url:mixed,status:mixed,error:mixed}>
     */
    private function fetchErrors(array $fetches): array
    {
        return array_values(array_map(
            fn (array $f) => ['url' => $f['url'] ?? null, 'status' => $f['status'] ?? null, 'error' => $f['error'] ?? null],
            array_filter($fetches, fn ($f) => is_array($f) && ($f['error'] ?? null) !== null),
        ));
    }

    /**
     * Slice 13c: the web attempt produced nothing usable (an empty draft, or a draft its own page did not support). Ask hr-ai
     * for the model-knowledge draft instead (`skip_web`: no catalogue fetch, the dedicated prompt). Only ever called with the
     * sub-flag on. If that second draft is unavailable, `$original` — exactly what the web attempt alone would have returned
     * — stands. The trace says a fallback happened, why, and what the abandoned web attempt cost.
     *
     * @param  array{text:string,kinds:list<string>,count:int}  $scrub
     * @param  array<string,mixed>  $webRaw  the first (web) envelope
     */
    private function modelFallback(TurnState $state, array $scrub, AnswerModelSetting $settings, array $webRaw, string $reason, ToolResult $original): ToolResult
    {
        $raw = $this->draft($settings, $scrub['text'], true, true);
        if (isset($raw['error']) || ! is_string($raw['answer'] ?? null) || $raw['answer'] === '') {
            return $original;
        }

        $webFragment = is_array($webRaw['trace_fragment'] ?? null) ? $webRaw['trace_fragment'] : [];
        $webFetches = is_array($webFragment['fetches'] ?? null) ? $webFragment['fetches'] : [];

        return $this->modelResult($state, $scrub, $raw, [
            'from' => ModelKnowledgeShapeCheck::BASIS_WEB,
            'reason' => $reason,
            'web_cost_usd' => $webFragment['cost_usd'] ?? null,
            'web_draft_words' => is_string($webRaw['answer'] ?? null) ? ModelKnowledgeShapeCheck::wordCount($webRaw['answer']) : 0,
            'fetches' => $webFetches,
            'fetch_errors' => $this->fetchErrors($webFetches),
        ]);
    }

    /**
     * The model-basis TERMINAL answer: nothing was fetched into it, so there is nothing to entail against — NO `/ground` call
     * and NO citation row (citations need a real document_id; none is fabricated). The deterministic locks run after this, as
     * rules on this TERMINAL result.
     *
     * @param  array{text:string,kinds:list<string>,count:int}  $scrub
     * @param  array<string,mixed>  $raw
     * @param  array<string,mixed>|null  $fallback  set only when this answer replaced a failed web attempt
     */
    private function modelResult(TurnState $state, array $scrub, array $raw, ?array $fallback): ToolResult
    {
        $fragment = is_array($raw['trace_fragment'] ?? null) ? $raw['trace_fragment'] : [];
        $fetches = $fallback !== null ? $fallback['fetches'] : (is_array($fragment['fetches'] ?? null) ? $fragment['fetches'] : []);

        $generalLaneTrace = [
            'basis' => ModelKnowledgeShapeCheck::BASIS_MODEL,
            'question_scrubbed' => $scrub['text'],
            'scrub' => ['kinds' => $scrub['kinds'], 'count' => $scrub['count']],
            'sources' => [['kind' => 'model_knowledge', 'title' => 'conocimiento general del modelo']],
            'fetches' => $fetches,
            'web_attempted' => $fetches !== [],
            'fetch_errors' => $this->fetchErrors($fetches),
            'prompt_sha256' => $fragment['prompt_sha256'] ?? null,
            'word_count' => ModelKnowledgeShapeCheck::wordCount($raw['answer']),
            'draft' => array_intersect_key($fragment, array_flip(['model', 'general_knowledge_ms', 'prompt_tokens', 'completion_tokens', 'cost_usd'])),
        ];
        if ($fallback !== null) {
            $generalLaneTrace['fallback'] = array_diff_key($fallback, ['fetches' => null, 'fetch_errors' => null]);
        }
        $generalLaneTrace['grounding'] = ['checked' => false, 'reason' => 'model_knowledge_no_source'];
        $generalLaneTrace['postcheck'] = ['passed' => GeneralLanePostCheck::scan($raw['answer']) === null];
        $shape = ModelKnowledgeShapeCheck::check($raw['answer'], ModelKnowledgeShapeCheck::BASIS_MODEL);
        $generalLaneTrace['shape'] = ['verdict' => $shape['verdict'], 'rule_ids' => $shape['rule_ids']];

        $trace = $state->trace;
        $trace['floor_decision'] = [
            'path' => 'general_knowledge',
            'outcome' => 'answer',
            'authority_used' => ['general_knowledge'],
        ];
        $trace['general_lane'] = $generalLaneTrace;

        return new ToolResult(
            ToolResult::TERMINAL,
            terminalOutcome: new TurnOutcome('answer', $raw['answer'], [], $trace, null),
            plannerSummary: ['status' => 'answer', 'basis' => ModelKnowledgeShapeCheck::BASIS_MODEL],
        );
    }
}
