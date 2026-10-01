<?php

namespace App\Services\Agent\Tools;

use App\Models\AnswerModelSetting;
use App\Services\Agent\Normalization\ConsumerTrace;
use App\Services\Agent\Rules\CorpusMiss;
use App\Services\Agent\Rules\ProseCheckAPostCallRule;
use App\Services\Agent\Tool;
use App\Services\Agent\ToolResult;
use App\Services\Agent\TurnState;
use App\Services\Answer\ProsePath;
use App\Services\GuardrailPolicy;
use App\Services\RouterService;

/**
 * Sprint 13, build step 5 (plan.md §B.3.3) — wraps `ProsePath::handle()`
 * (today's prose path, including decomposition) exactly as classic's own
 * prose branch does, minus the LLM router call classic uses to get there
 * (the planner replaces that decision entirely — the agent shell never
 * calls `RouterService::classify()`).
 *
 * `run()` calls `ProsePath::handle()` in FULL, in one shot, same as
 * `SalaryLookupTool`/`ReferenceFactTool` — R14 (aggregation) and R15
 * (`expired_only` → `estatuto_fallback_gap`) are NOT separate pre-call
 * `Rule` objects here: both checks are already baked into
 * `ProsePath::handle()` itself (extracted verbatim in step 1), so calling it
 * in full reproduces them automatically, exactly like `salary_lookup`'s R08
 * SMI check needed no separate pre-call rule either.
 *
 * The one genuinely new behaviour (§B.3.3's post-call rule, implemented in
 * {@see ProseCheckAPostCallRule}, shared with
 * `national_law`): an R16 Check-A-retrieval-floor failure is NOT forced —
 * no synthesis call has been spent yet, so the planner may still try
 * `national_law` or `general_knowledge`. Every OTHER escalate (aggregation,
 * `estatuto_fallback_gap`, or a low_confidence AFTER Check A passed —
 * Check B/figure-guard/grounding all already spent the synthesis call) IS
 * forced, since retrying with a different tool cannot un-spend that call or
 * change what was already retrieved (this tool's own union already includes
 * the national-law pass and the precedence re-rank, `RetrievalUnion.php`).
 * ONE exception, added at CP-1 (F.8 amendment, see {@see CorpusMiss}): a
 * post-Check-A failure whose ONLY failing gate is per-claim entailment is
 * `NO_MATERIAL` (`entailment_failed`, escalation stashed) when the lane is on
 * and the question passes the explanatory pre-screen.
 */
final class ConvenioSearchTool implements Tool
{
    public function __construct(
        private readonly ProsePath $prosePath,
        private readonly RouterService $router,
        private readonly GuardrailPolicy $guardrails,
    ) {}

    public function name(): string
    {
        return 'convenio_search';
    }

    public function definition(): array
    {
        return [
            'name' => 'convenio_search',
            'description' => 'Busca en el texto del convenio de la persona y en la normativa aplicable '
                .'(incluye automáticamente el Estatuto de los Trabajadores como respaldo cuando aplica). '
                .'Úsala para preguntas sobre el contenido del convenio (permisos, jornada, vacaciones, '
                .'excedencias, qué significa IT, etc.) después de que reference_fact no encuentre un dato '
                .'verificado para el tema.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'query' => ['type' => 'string', 'description' => 'La pregunta a buscar; por defecto, la pregunta original de la persona.'],
                    'subqueries' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 4, 'description' => 'Sub-preguntas de una pregunta compuesta (máx. 4).'],
                    'decomposed_queries' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 3, 'description' => 'Reformulaciones situacionales/coloquiales para mejorar la recuperación (máx. 3).'],
                ],
                'additionalProperties' => false,
            ],
        ];
    }

    public function countsAsClarification(): bool
    {
        return false;
    }

    public function run(array $input, TurnState $state): ToolResult
    {
        // Sprint 13b (plan.md §4.2, finding 7): the employee's LITERAL question is what synthesis and grounding
        // see — always. A planner `query` used to REPLACE it (and `/synthesise` + `/ground` then answered a
        // sentence the employee never wrote); it is now one more retrieval-only rephrasing, like
        // `decomposed_queries`. Agent-only (classic never reaches this tool).
        $question = $state->question;
        $plannerQuery = is_string($input['query'] ?? null) && trim($input['query']) !== '' && trim($input['query']) !== $state->question
            ? trim($input['query'])
            : null;

        $subqueries = $this->cappedStrings($input['subqueries'] ?? null, 4);
        if ($subqueries === []) {
            // Classic's own fallback when the router sends no subqueries
            // (`RouterService.php:269-279`) — the planner supplying none is
            // the same shape as the router never having split the question.
            $subqueries = $this->router->deterministicSplit($question);
        }
        $plannerExtras = $this->cappedStrings($input['decomposed_queries'] ?? null, 3);
        if ($plannerQuery !== null) {
            array_unshift($plannerExtras, $plannerQuery);
        }
        // Sprint 13b: a validated canonical is ADDED to retrieval (union) — never in place of the literal.
        $canonical = $state->normalizedCanonical();
        $decomposedQueries = self::withCanonical($canonical, $plannerExtras);

        $settings = AnswerModelSetting::current();
        $decryptedKey = $settings->isConfigured() ? $settings->decryptKey() : null;

        $outcome = $this->prosePath->handle($state->employee, $question, $subqueries, $state->asOfDate, $decryptedKey, $state->trace, $decomposedQueries, $canonical !== null);

        if ($canonical !== null) {
            $state->recordNormalizationConsumer(ConsumerTrace::retrieval('convenio_search', $outcome, $canonical, $this->guardrails->retrievalFloor()));
        }

        if ($outcome->outcome === 'answer') {
            return new ToolResult(ToolResult::TERMINAL, terminalOutcome: $outcome, plannerSummary: [
                'status' => 'answer',
            ]);
        }

        $checkA = $outcome->trace['floor_decision']['check_a_retrieval'] ?? true;
        if ($checkA === false) {
            // R16 only — no synthesis call spent yet; NOT terminal, mirrors
            // `ReferenceFactTool`'s `no_fact` shape (§B.3.3's post-call rule).
            return new ToolResult(
                ToolResult::NO_MATERIAL,
                terminalOutcome: $outcome, // stashed for the finisher, see class docblock — not forced
                traceBlocks: ['floor_decision' => $outcome->trace['floor_decision'], 'retrieval' => $outcome->trace['retrieval'] ?? []],
                plannerSummary: ['status' => 'check_a_failed', 'gap_class' => $outcome->trace['prose_gap']['classification'] ?? null],
            );
        }

        // CP-1 amendment (F.8, plan §B.6.1 cond. 2): a post-Check-A failure
        // whose ONLY failing gate is per-claim entailment is handed back to the
        // planner (stashed for the finisher, exactly like the R16 miss) when
        // the lane is on and the question passes the explanatory pre-screen.
        // Lane off / prescreen hit / any other verdict → terminal as before.
        // Slice 13c: with the model-knowledge sub-flag on, a synthesis ABSTENTION (status `abstained`) is handed over the same way.
        $handOver = CorpusMiss::handOverKind($outcome, $state->question, $this->guardrails);
        if ($handOver !== null) {
            return new ToolResult(
                ToolResult::NO_MATERIAL,
                terminalOutcome: $outcome,
                traceBlocks: ['floor_decision' => $outcome->trace['floor_decision'], 'retrieval' => $outcome->trace['retrieval'] ?? []] + CorpusMiss::precondition($outcome),
                plannerSummary: ['status' => $handOver === CorpusMiss::SYNTHESIS_ABSTENTION ? 'abstained' : 'entailment_failed'],
            );
        }

        // Aggregation guard, estatuto_fallback_gap, or a post-Check-A
        // low_confidence (Check B / figure-guard / grounding) — every one of
        // these already spent whatever it was going to spend; terminal.
        return new ToolResult(ToolResult::TERMINAL, terminalOutcome: $outcome, plannerSummary: [
            'status' => 'escalate',
            'escalation_reason' => $outcome->escalationReason,
        ]);
    }

    /**
     * Canonical first, then the planner's own extras (deduped case-insensitively, at most 3 of them — the
     * cap that always applied to `decomposed_queries`).
     *
     * @param  list<string>  $extras
     * @return list<string>
     */
    private static function withCanonical(?string $canonical, array $extras): array
    {
        $seen = $canonical !== null ? [mb_strtolower($canonical) => true] : [];
        $out = [];
        foreach ($extras as $e) {
            $k = mb_strtolower(trim($e));
            if ($k === '' || isset($seen[$k])) {
                continue;
            }
            $seen[$k] = true;
            $out[] = $e;
            if (count($out) >= 3) {
                break;
            }
        }

        return $canonical !== null ? [$canonical, ...$out] : $out;
    }

    /** @return list<string> */
    private function cappedStrings(mixed $value, int $max): array
    {
        if (! is_array($value)) {
            return [];
        }

        $strings = array_values(array_filter($value, 'is_string'));

        return array_slice($strings, 0, $max);
    }
}
