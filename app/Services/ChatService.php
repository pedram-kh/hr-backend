<?php

namespace App\Services;

use App\Models\AnswerModelSetting;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\Document;
use App\Models\Employee;
use App\Models\EscalationCard;
use App\Models\MessageCitation;
use App\Models\MessageTrace;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The answer pipeline orchestrator. Sprint 2b-2 completes the answer surface:
 * scope resolve → guardrail baseline → ROUTER → {salary SQL | prose | off-domain}.
 * Everything here runs in hr-backend EXCEPT the hr-ai calls (/route, /retrieve,
 * /synthesise, /ground). hr-backend resolves scope deterministically, owns the
 * answer-or-escalate DECISION (legal weight), and owns ALL DB writes (ADR-0007).
 *
 * Pipeline (architecture.md §2/§5, ADR-0007/0015/0016):
 *   1. resolve scope (deterministic SQL — no LLM)
 *   2. guardrail baseline (deterministic; fires BEFORE any hr-ai call — sensitive
 *      / legal-medical / other-employee escalate first; the router never sees them)
 *   3. ROUTER (ADR-0016): salary | prose | off_domain. A deterministic salary
 *      pre-classifier short-circuits obvious salary (no LLM); else the small-model
 *      /route call. FAIL-SAFE: uncertainty/error/no-key → safe prose path.
 *   4a. SALARY → SalaryAnswerService (SQL; exact, year-aligned; ADR-0006) → answer
 *       / constrained category pick / coverage-gap escalate.
 *   4b. PROSE → recall-hardened /retrieve (subquery union + national-law pass) →
 *       pre-synthesis floor (Check A) → /synthesise → A∧B → figure-guard pre-check
 *       → /ground per-claim entailment GATE (§5).
 *   4c. OFF-DOMAIN → escalate (off_domain).
 *   5. persist (session, messages, citations, trace incl. router_decision +
 *      grounding + salary blocks; escalation_card on escalate — NOT on a pick).
 */
class ChatService
{
    /** Surfaced to the employee on escalation (design-system voice). */
    public const ESCALATION_MESSAGE = 'No estoy seguro de la respuesta a esta pregunta, '
        .'así que la estoy pasando a una persona del equipo de Recursos Humanos.';

    /** Surfaced on a vague "total días libres" aggregation (Correction-03, Fix 2). */
    public const AGGREGATION_MESSAGE = 'Para darte una cifra fiable necesito que me preguntes por un '
        .'tipo concreto de días libres (por ejemplo, las vacaciones, los días de asuntos propios o un '
        .'permiso específico). Sumar todos los tipos en un único "total" no es un dato que pueda '
        .'fundamentar con exactitud en tu convenio, así que te derivo con una persona del equipo de '
        .'Recursos Humanos.';

    /** Surfaced on a salary+prose cross-path compound (Correction-03, Fix 3). */
    public const CROSSPATH_MESSAGE = 'Tu pregunta combina dos cosas: la parte salarial puedo '
        .'consultarla en las tablas, pero también preguntas por otro tema (por ejemplo, las vacaciones) '
        .'que necesita una persona del equipo de Recursos Humanos. Te derivo para que te respondan ambas '
        .'partes correctamente.';

    /** Max chunks handed to synthesis after the recall-hardening union (top by score). */
    private const SYNTHESIS_CHUNK_CAP = 10;

    /**
     * Extra synthesis slots per decomposed sub-query (Correction-03, Fix 1). A
     * COMPOUND question unions several passes (main + each sub-query + national
     * law); capping that union at the single-topic cap truncates one sub-topic's
     * recall below what a focused query would surface (e.g. Navarra's buried
     * "37 días laborables" grant chunk 7721 lands at ~#12 once vacaciones +
     * periodo-de-prueba chunks share the pool). The cap grows with the number of
     * sub-queries so each sub-topic keeps its recall; a single-topic question
     * (no sub-queries) is unchanged at SYNTHESIS_CHUNK_CAP.
     */
    private const COMPOUND_CAP_PER_SUBQUERY = 2;

    /**
     * Precedence re-rank topic lexicon (Correction-03, Fix 1). Two chunks "cover
     * the same topic" when both contain an anchor term of the same topic; a
     * convenio chunk on a topic is then promoted above the national_law (Estatuto)
     * chunk on that same topic, so the convenio's governing figure displaces the
     * baseline. When the convenio is genuinely silent on a topic (no convenio
     * chunk carries its anchor — e.g. trabajo a distancia in many convenios) there
     * is nothing to promote, so the national_law chunk is left untouched to fill
     * the gap. Anchors are matched accent-insensitively on lowercased text.
     *
     * @var array<string, list<string>>
     */
    private const TOPIC_ANCHORS = [
        'vacaciones' => ['vacaciones', 'vacacional', 'periodo vacacional'],
        'jornada' => ['jornada', 'horas anuales', 'computo anual', 'horario de trabajo'],
        'permisos' => ['permiso', 'permisos', 'licencia', 'licencias', 'dias de asuntos propios', 'asuntos propios'],
        'excedencia' => ['excedencia', 'excedencias'],
        'periodo_prueba' => ['periodo de prueba', 'período de prueba'],
        'trabajo_distancia' => ['trabajo a distancia', 'teletrabajo'],
        'horas_extra' => ['horas extraordinarias', 'horas extra'],
        'preaviso' => ['preaviso'],
        'lactancia' => ['lactancia'],
        'maternidad' => ['maternidad', 'paternidad', 'nacimiento', 'adopcion'],
        'descanso' => ['descanso semanal', 'descanso diario', 'dias de descanso'],
        'festivos' => ['festivos', 'dias festivos', 'fiestas laborales'],
        'movilidad' => ['movilidad geografica', 'traslado', 'desplazamiento'],
        'antiguedad' => ['antiguedad', 'trienios', 'quinquenios'],
        'despido' => ['despido', 'extincion del contrato', 'finiquito'],
        'ascensos' => ['ascensos', 'promocion', 'clasificacion profesional'],
    ];

    /** Tiny margin used to lift a governing convenio chunk just above the baseline it competes with. */
    private const PRECEDENCE_EPSILON = 0.0001;

    public function __construct(
        private readonly ExtractionClient $ai,
        private readonly GuardrailService $guardrail,
        private readonly RouterService $router,
        private readonly SalaryAnswerService $salary,
        private readonly GroundingService $grounding,
    ) {}

    /**
     * Handle one employee turn end to end. Always persists both messages + a full
     * trace; creates an escalation_card on any escalate outcome (NOT on a category
     * pick). `$selectedJobCategoryId` is the unverified category from a salary
     * disambiguation follow-up (§4).
     *
     * @return array<string,mixed> the response payload for the API/UI
     */
    public function handleMessage(Employee $employee, string $question, ?string $sessionUuid = null, ?int $selectedJobCategoryId = null): array
    {
        $asOfDate = Carbon::today();
        $session = $this->resolveSession($employee, $sessionUuid);

        $employee->loadMissing('convenio');
        $trace = [
            'profile' => [
                'employee_uuid' => $employee->uuid,
                'convenio_id' => $employee->convenio_id,
                'convenio_numero' => $employee->convenio?->numero,
                'territory_id' => $employee->territory_id,
                'job_category_id' => $employee->job_category_id,
            ],
            'scope_filters' => [
                'convenio_id' => $employee->convenio_id,
                'include_national_law' => true,
                'retrieval_status' => ['active'],
                'as_of_date' => $asOfDate->toDateString(),
            ],
            'router_decision' => null,
            'guardrail_check' => ['fired' => false, 'reason' => null, 'rule' => null],
        ];

        // --- Step 2: guardrail baseline (BEFORE the router and any hr-ai call) ---
        $guard = $this->guardrail->check($question);
        if ($guard['fired']) {
            $trace['guardrail_check'] = $guard;
            $trace['floor_decision'] = [
                'retrieval_score_floor' => (float) config('hr.retrieval_score_floor'),
                'answer_confidence_floor' => (float) config('hr.answer_confidence_floor'),
                'outcome' => 'escalate',
                'escalation_reason' => $guard['reason'],
            ];

            return $this->persistTurn($session, $employee, $question, self::ESCALATION_MESSAGE, [], $trace, 'escalate', $guard['reason']);
        }

        // --- Step 3: router (ADR-0016) ------------------------------------------
        // Decrypt the answer-model key ONCE for this turn (if configured); reused
        // for the router (LLM), synthesis, and grounding; dropped at the end. The
        // deterministic salary pre-classifier inside the router needs no key.
        $settings = AnswerModelSetting::current();
        $decryptedKey = $settings->isConfigured() ? $settings->decryptKey() : null;

        $routerConfig = [
            'provider' => config('services.hr_ai.answer_provider', 'claude'),
            'model' => config('services.hr_ai.router_model'),
            'endpoint' => config('services.hr_ai.router_endpoint'),
        ];
        $decision = $this->router->classify($question, $decryptedKey, $routerConfig);
        $trace['router_decision'] = [
            'label' => $decision['label'],
            'confidence' => $decision['confidence'],
            'source' => $decision['source'],
            'subqueries' => $decision['subqueries'],
            'model' => $decision['model'],
            'note' => $decision['note'],
            'cross_path' => $decision['cross_path'] ?? false,
            'trace_fragment' => $decision['trace_fragment'] ?? [],
        ];

        // --- Step 4c: off-domain → escalate -------------------------------------
        if ($decision['label'] === RouterService::OFF_DOMAIN) {
            unset($decryptedKey);
            $trace['floor_decision'] = [
                'retrieval_score_floor' => (float) config('hr.retrieval_score_floor'),
                'answer_confidence_floor' => (float) config('hr.answer_confidence_floor'),
                'outcome' => 'escalate',
                'escalation_reason' => 'off_domain',
                'note' => 'router classified off_domain',
            ];

            return $this->persistTurn($session, $employee, $question, self::ESCALATION_MESSAGE, [], $trace, 'escalate', 'off_domain');
        }

        // --- Step 4a: salary → SQL path (exact, year-aligned; ADR-0006) ---------
        if ($decision['label'] === RouterService::SALARY) {
            unset($decryptedKey); // the salary path is pure SQL — no provider call

            // Fix 3 (Correction-03): a salary+prose CROSS-PATH compound (the salary
            // pre-classifier matched, but the question also has a clear non-salary
            // clause). The old behaviour short-circuited the whole turn to SQL and
            // silently dropped the prose half. Escalate-with-note instead, so the
            // prose half is surfaced to a human, never silently dropped.
            if ($decision['cross_path'] ?? false) {
                $trace['floor_decision'] = [
                    'path' => 'salary_prose_crosspath',
                    'outcome' => 'escalate',
                    'escalation_reason' => 'low_confidence',
                    'cross_path' => [
                        'detected_by' => $decision['source'],
                        'prose_subqueries' => $decision['subqueries'],
                    ],
                    'note' => 'salary+prose cross-path compound — escalated with note so the prose half is not silently dropped (Correction-03)',
                ];

                return $this->persistTurn($session, $employee, $question, self::CROSSPATH_MESSAGE, [], $trace, 'escalate', 'low_confidence');
            }

            $result = $this->salary->answer($employee, $asOfDate, $selectedJobCategoryId);
            $trace['salary'] = $result['salary'];

            $outcome = $result['outcome'];
            if ($outcome === SalaryAnswerService::OUTCOME_ANSWER) {
                $trace['floor_decision'] = [
                    'path' => 'salary_sql',
                    'outcome' => 'answer',
                    'escalation_reason' => null,
                    'note' => 'exact figure from salary_tables (year '.($result['salary']['year'] ?? '?').')',
                ];

                return $this->persistTurn($session, $employee, $question, $result['answer'], $result['citations'], $trace, 'answer', null);
            }

            if ($outcome === SalaryAnswerService::OUTCOME_NEEDS_CATEGORY) {
                $trace['floor_decision'] = [
                    'path' => 'salary_sql',
                    'outcome' => 'needs_category',
                    'escalation_reason' => null,
                    'note' => 'constrained category pick offered (single-turn, §4)',
                ];

                // A pick is NOT an escalation and NOT an answer → no card, no citations.
                return $this->persistTurn($session, $employee, $question, $result['answer'], [], $trace, 'needs_category', null, $result['categories']);
            }

            // coverage gap → escalate salary_coverage_gap (supersedes salary_not_in_chat)
            $trace['floor_decision'] = [
                'path' => 'salary_sql',
                'outcome' => 'escalate',
                'escalation_reason' => $result['escalation_reason'] ?? 'salary_coverage_gap',
                'note' => $result['salary']['note'] ?? 'salary coverage gap',
            ];

            return $this->persistTurn($session, $employee, $question, $result['answer'], [], $trace, 'escalate', $result['escalation_reason'] ?? 'salary_coverage_gap');
        }

        // --- Step 4b: prose path -------------------------------------------------
        return $this->answerProse($session, $employee, $question, $decision['subqueries'], $asOfDate, $decryptedKey, $trace);
    }

    /**
     * The prose answer path (recall-hardened retrieve → floor → synthesise →
     * A∧B → figure-guard pre-check → per-claim entailment gate). Returns the
     * persist payload.
     *
     * @param  list<string>  $subqueries
     * @param  array<string,mixed>  $trace
     * @return array<string,mixed>
     */
    private function answerProse(ChatSession $session, Employee $employee, string $question, array $subqueries, Carbon $asOfDate, ?string $decryptedKey, array $trace): array
    {
        $retrievalFloor = (float) config('hr.retrieval_score_floor');
        $confidenceFloor = (float) config('hr.answer_confidence_floor');

        // --- Fix 2 (Correction-03): vague "total días libres" aggregation guard --
        // A "¿cuántos días libres … en total?" asks to SUM across leave types
        // (vacaciones + festivos + permisos + asuntos propios) — an arithmetic
        // aggregation, not a single grounded fact. Summing leave types is
        // unsupported synthesis even with the right figures in hand, so escalate
        // (audit-first) BEFORE retrieval. Narrow detector: a GENERIC leave phrase
        // ("días libres"/"días de descanso") AND a total/aggregation marker — a
        // named single-topic question ("¿cuántas vacaciones tengo?") never trips it.
        if ($this->isVagueAggregationTotal($question)) {
            unset($decryptedKey);
            $trace['aggregation_guard'] = ['fired' => true, 'shape' => 'vague_total_dias_libres'];
            $trace['floor_decision'] = [
                'retrieval_score_floor' => $retrievalFloor,
                'answer_confidence_floor' => $confidenceFloor,
                'outcome' => 'escalate',
                'escalation_reason' => 'low_confidence',
                'note' => 'aggregation/vague-total query — summing leave types is not a single grounded fact (Correction-03)',
            ];

            return $this->persistTurn($session, $employee, $question, self::AGGREGATION_MESSAGE, [], $trace, 'escalate', 'low_confidence');
        }

        // Recall hardening (§6, resolved §9 F): one /retrieve for the question,
        // one per decomposed sub-query (compound questions — the Q10 fix), plus a
        // national-law-only pass (the silent-topic recall — the Art. 14 ET miss).
        // Union, dedupe by chunk_id keeping the max score. /retrieve is unchanged.
        $union = $this->retrieveUnion($question, $subqueries, $employee->convenio_id, $asOfDate->toDateString());
        $chunks = $union['chunks'];
        $eligibleTotal = $union['eligible_total'];
        $topScore = empty($chunks) ? 0.0 : (float) collect($chunks)->max('score');

        $trace['retrieval'] = [
            'eligible_total' => $eligibleTotal,
            'returned' => count($chunks),
            'top_score' => round($topScore, 6),
            'passes' => $union['passes'], // per-query recall-hardening detail
            'rerank' => $union['rerank'], // widened-pool precedence re-rank (Correction-03)
            'chunks' => collect($chunks)->map(fn ($c) => [
                'chunk_id' => $c['id'] ?? null,
                'document_id' => $c['document_id'] ?? null,
                'page_from' => $c['page_from'] ?? null,
                'page_to' => $c['page_to'] ?? null,
                'score' => $c['score'] ?? null,
                'authority_level' => $c['authority_level'] ?? null,
            ])->all(),
        ];

        // --- Check A: pre-synthesis floor ---------------------------------------
        if ($topScore < $retrievalFloor) {
            unset($decryptedKey);
            $trace['floor_decision'] = [
                'retrieval_score_floor' => $retrievalFloor,
                'answer_confidence_floor' => $confidenceFloor,
                'check_a_retrieval' => false,
                'outcome' => 'escalate',
                'escalation_reason' => 'low_confidence',
                'note' => $eligibleTotal === 0 ? 'no eligible chunks' : 'eligible chunks but all below retrieval floor',
            ];

            return $this->persistTurn($session, $employee, $question, self::ESCALATION_MESSAGE, [], $trace, 'escalate', 'low_confidence');
        }

        // --- Answer model must be configured to synthesise ----------------------
        if ($decryptedKey === null) {
            $trace['synthesis'] = ['skipped' => 'answer_model_not_configured'];
            $trace['floor_decision'] = [
                'retrieval_score_floor' => $retrievalFloor,
                'answer_confidence_floor' => $confidenceFloor,
                'check_a_retrieval' => true,
                'outcome' => 'escalate',
                'escalation_reason' => 'low_confidence',
                'note' => 'answer model not configured',
            ];

            return $this->persistTurn($session, $employee, $question, self::ESCALATION_MESSAGE, [], $trace, 'escalate', 'low_confidence');
        }

        // --- Step 5: synthesise (convenio chunks ordered before national_law) ----
        $orderedChunks = $this->orderByAuthority($chunks);
        $synthesisChunks = collect($orderedChunks)->map(fn ($c) => [
            'chunk_id' => $c['id'],
            'document_id' => $c['document_id'],
            'page_from' => $c['page_from'] ?? null,
            'page_to' => $c['page_to'] ?? null,
            'content' => $c['content'],
            'score' => $c['score'] ?? 0.0,
            'authority_level' => $c['authority_level'] ?? null,
        ])->all();
        $providedChunkIds = collect($orderedChunks)->pluck('id')->map(fn ($v) => (int) $v)->all();

        $providerConfig = [
            'provider' => config('services.hr_ai.answer_provider', 'claude'),
            'model' => config('services.hr_ai.answer_model'),
            'endpoint' => config('services.hr_ai.answer_endpoint'),
        ];

        $synth = $this->ai->synthesise($question, $synthesisChunks, $decryptedKey, $providerConfig);

        if (isset($synth['error'])) {
            Log::warning('chat: synthesis provider failure', ['error' => $synth['error']]); // never logs the key
            unset($decryptedKey);
            $trace['synthesis'] = ['provider' => $providerConfig['provider'], 'model' => $providerConfig['model'], 'error' => $synth['error']];
            $trace['floor_decision'] = [
                'retrieval_score_floor' => $retrievalFloor,
                'answer_confidence_floor' => $confidenceFloor,
                'check_a_retrieval' => true,
                'outcome' => 'escalate',
                'escalation_reason' => 'low_confidence',
                'note' => 'provider error',
            ];

            return $this->persistTurn($session, $employee, $question, self::ESCALATION_MESSAGE, [], $trace, 'escalate', 'low_confidence');
        }

        // --- Step 6: answer-or-escalate decision --------------------------------
        $answer = trim((string) ($synth['answer'] ?? ''));
        $grounding = $synth['grounding_signal'] ?? [];
        $confidence = (float) ($synth['confidence'] ?? 0.0);
        $authorityUsed = $synth['authority_used'] ?? [];

        // Check B (load-bearing): citations present AND every cited chunk_id was in
        // the provided set (reject hallucinated citations).
        $validCitations = [];
        foreach (($synth['citations'] ?? []) as $cit) {
            $cid = isset($cit['chunk_id']) ? (int) $cit['chunk_id'] : null;
            if ($cid !== null && in_array($cid, $providedChunkIds, true)) {
                $validCitations[] = $cit;
            }
        }
        $checkB = count($validCitations) >= 1 && $answer !== '';
        $confidenceBelowFloor = $confidence < $confidenceFloor;

        // Figure-grounding guard (Correction-01): a cheap deterministic PRE-CHECK
        // feeding the entailment gate (NOT the gate itself any more — §5). Fires
        // when a load-bearing figure is entirely absent from cited chunks, BEFORE
        // spending the /ground LLM call (the spec's "figure-guard short-circuits
        // before /ground").
        $citedTexts = $this->citedChunkTexts($validCitations, $chunks);
        $figureGuard = $this->checkFigureGrounding($answer, $citedTexts);

        $trace['synthesis'] = [
            'provider' => $providerConfig['provider'],
            'model' => $providerConfig['model'],
            'citation_count' => count($validCitations),
            'confidence' => $confidence,
            'grounding_signal' => $grounding,
            'authority_used' => $authorityUsed,
            'trace_fragment' => $synth['trace_fragment'] ?? [],
        ];

        // Gate order: A (already passed) ∧ B ∧ figure-guard pre-check ∧ entailment.
        // Each failure escalates (low_confidence) in the safe direction.
        $groundingResult = null;
        if (! $checkB) {
            $note = 'no valid citations (Check B failed)';
        } elseif (! $figureGuard['grounded']) {
            $note = 'answer figure not grounded in cited chunk (figure-guard pre-check)';
        } else {
            // The REAL gate (§5): per-claim entailment with the CAPABLE answer
            // model. Table-aware. Any ungrounded claim → escalate (resolved §9 B).
            $citedChunksForGround = $this->citedChunksForGrounding($validCitations, $chunks);
            $groundingResult = $this->grounding->check($question, $answer, $citedChunksForGround, $decryptedKey, $providerConfig);
            // A truncated grounding check (Correction-04) is a DISTINCT outcome from
            // a genuine ungrounded claim: it still escalates (conservative floor),
            // but the trace must not read it as a fabricated claim.
            if ($groundingResult['grounded']) {
                $note = null;
            } elseif (($groundingResult['trace_fragment']['grounding_truncated'] ?? false)) {
                $note = 'grounding check truncated after retry (escalated)';
            } else {
                $note = 'ungrounded claim (per-claim entailment gate)';
            }
        }
        unset($decryptedKey); // drop the plaintext as soon as all provider calls are done

        $decisionPass = $checkB && $figureGuard['grounded'] && ($groundingResult !== null && $groundingResult['grounded']);

        $floor = [
            'retrieval_score_floor' => $retrievalFloor,
            'answer_confidence_floor' => $confidenceFloor,
            'check_a_retrieval' => true,
            'check_b_citations' => $checkB,
            'check_c_confidence_tiebreaker' => ['confidence' => $confidence, 'below_floor' => $confidenceBelowFloor, 'used_as_gate' => false],
            'figure_grounding' => $figureGuard,
            'grounding' => $groundingResult === null
                ? ['checked' => false, 'reason' => 'short-circuited before /ground']
                : [
                    'checked' => true,
                    'grounded' => $groundingResult['grounded'],
                    'claims' => $groundingResult['claims'],
                    'ungrounded' => $groundingResult['ungrounded'],
                    'error' => $groundingResult['error'] ?? null,
                    'gate' => 'entailment',
                    'trace_fragment' => $groundingResult['trace_fragment'] ?? [],
                ],
            'authority_used' => $authorityUsed,
            'outcome' => $decisionPass ? 'answer' : 'escalate',
            'escalation_reason' => $decisionPass ? null : 'low_confidence',
            'note' => $decisionPass ? null : $note,
        ];
        $trace['floor_decision'] = $floor;

        if (! $decisionPass) {
            return $this->persistTurn($session, $employee, $question, self::ESCALATION_MESSAGE, [], $trace, 'escalate', 'low_confidence');
        }

        $citations = $this->resolveCitations($validCitations, $chunks);

        return $this->persistTurn($session, $employee, $question, $answer, $citations, $trace, 'answer', null);
    }

    /**
     * Recall hardening (§6): issue /retrieve for the question + each decomposed
     * sub-query + a national-law-only pass, then UNION (dedupe by chunk_id keeping
     * the max score), sort by score desc, cap to SYNTHESIS_CHUNK_CAP. /retrieve is
     * unchanged (the union is hr-backend-side — resolved §9 F).
     *
     * @param  list<string>  $subqueries
     * @return array{chunks:list<array<string,mixed>>, eligible_total:int, passes:list<array<string,mixed>>, rerank:array<string,mixed>}
     */
    private function retrieveUnion(string $question, array $subqueries, ?int $convenioId, string $asOf): array
    {
        $byChunkId = [];
        $passes = [];
        $maxEligible = 0;
        $poolK = (int) config('hr.retrieval_pool_k', 25);
        $nlK = (int) config('hr.retrieval_national_law_k', 8);

        // The main question + each sub-query: scoped (convenio + national law).
        $queries = array_merge([$question], $subqueries);
        foreach ($queries as $i => $q) {
            $resp = $this->safeRetrieve([
                'query' => $q,
                'convenio_id' => $convenioId,
                'include_national_law' => true,
                'retrieval_status' => ['active'],
                'as_of_date' => $asOf,
                'k' => $poolK,
            ]);
            $this->mergeChunks($byChunkId, $resp['chunks'] ?? []);
            $eligible = (int) ($resp['eligible_total'] ?? 0);
            $maxEligible = max($maxEligible, $eligible);
            $passes[] = [
                'kind' => $i === 0 ? 'main' : 'subquery',
                'query' => $q,
                'returned' => count($resp['chunks'] ?? []),
                'eligible_total' => $eligible,
                'top_score' => empty($resp['chunks'] ?? []) ? 0.0 : round((float) collect($resp['chunks'])->max('score'), 6),
            ];
        }

        // National-law-only pass (convenio_id = null) — surfaces the on-topic
        // Estatuto article for a silent-convenio topic even when convenio chunks
        // dominate the scoped top-k (the Art. 14 ET recall gap).
        $nl = $this->safeRetrieve([
            'query' => $question,
            'convenio_id' => null,
            'include_national_law' => true,
            'retrieval_status' => ['active'],
            'as_of_date' => $asOf,
            'k' => $nlK,
        ]);
        $this->mergeChunks($byChunkId, $nl['chunks'] ?? []);
        $passes[] = [
            'kind' => 'national_law',
            'query' => $question,
            'returned' => count($nl['chunks'] ?? []),
            'eligible_total' => (int) ($nl['eligible_total'] ?? 0),
            'top_score' => empty($nl['chunks'] ?? []) ? 0.0 : round((float) collect($nl['chunks'])->max('score'), 6),
        ];

        // Widened-pool precedence re-rank (Correction-03, Fix 1): on the FULL union
        // (before truncation) promote a governing convenio chunk above the
        // national_law chunk that covers the SAME topic, so the convenio's figure
        // displaces the baseline instead of being discarded by the raw-score cut.
        $merged = array_values($byChunkId);
        [$merged, $rerank] = $this->precedenceRerank($merged, $poolK);
        // The synthesis cap grows with the number of sub-queries so a compound
        // union isn't truncated below its parts' recall (Correction-03, Fix 1).
        $cap = self::SYNTHESIS_CHUNK_CAP + self::COMPOUND_CAP_PER_SUBQUERY * count($subqueries);
        $merged = array_slice($merged, 0, $cap);
        $rerank['synthesis_cap'] = $cap;

        return ['chunks' => $merged, 'eligible_total' => $maxEligible, 'passes' => $passes, 'rerank' => $rerank];
    }

    /**
     * Precedence re-rank over the widened candidate pool (Correction-03, Fix 1).
     *
     * For each governing (official_convenio / internal_hr_ruling) chunk, find the
     * national_law chunks that cover the SAME topic (shared topic anchor, see
     * TOPIC_ANCHORS); if any such baseline outranks it by raw score, lift its
     * EFFECTIVE score to just above the highest same-topic baseline so the
     * governing convenio chunk displaces the baseline in the truncated set. A
     * national_law chunk whose topic has NO convenio counterpart in the pool is
     * left untouched — so a genuinely silent convenio (e.g. trabajo a distancia)
     * still lets the Estatuto chunk fill the gap. Sorts by effective score desc
     * (raw score as the stable tiebreak). Returns [sortedChunks, rerankTrace].
     *
     * @param  list<array<string,mixed>>  $chunks
     * @return array{0: list<array<string,mixed>>, 1: array<string,mixed>}
     */
    private function precedenceRerank(array $chunks, int $poolK): array
    {
        // Precompute each chunk's topic set once.
        $topicsByIdx = [];
        $nlIdx = [];
        foreach ($chunks as $i => $c) {
            $topicsByIdx[$i] = $this->chunkTopics((string) ($c['content'] ?? ''));
            if (($c['authority_level'] ?? null) === 'national_law') {
                $nlIdx[] = $i;
            }
        }

        $boosted = [];
        foreach ($chunks as $i => $c) {
            $raw = (float) ($c['score'] ?? 0.0);
            $chunks[$i]['effective_score'] = $raw;

            if (($c['authority_level'] ?? null) === 'national_law' || empty($topicsByIdx[$i])) {
                continue; // baselines keep raw score; a topic-less convenio chunk isn't promoted
            }

            // Highest same-topic baseline score (and which chunk) this convenio
            // chunk competes with.
            $maxNl = null;
            $aboveNlId = null;
            foreach ($nlIdx as $j) {
                if (array_intersect_key($topicsByIdx[$i], $topicsByIdx[$j]) === []) {
                    continue; // different topic → not a precedence contest
                }
                $nlScore = (float) ($chunks[$j]['score'] ?? 0.0);
                if ($maxNl === null || $nlScore > $maxNl) {
                    $maxNl = $nlScore;
                    $aboveNlId = (int) ($chunks[$j]['id'] ?? 0);
                }
            }

            if ($maxNl !== null && $raw <= $maxNl) {
                $chunks[$i]['effective_score'] = $maxNl + self::PRECEDENCE_EPSILON;
                $boosted[] = [
                    'chunk_id' => (int) ($c['id'] ?? 0),
                    'topics' => array_keys($topicsByIdx[$i]),
                    'from_score' => round($raw, 6),
                    'to_effective' => round($maxNl + self::PRECEDENCE_EPSILON, 6),
                    'above_national_law_chunk_id' => $aboveNlId,
                ];
            }
        }

        usort($chunks, function ($a, $b) {
            $cmp = ($b['effective_score'] ?? ($b['score'] ?? 0.0)) <=> ($a['effective_score'] ?? ($a['score'] ?? 0.0));

            return $cmp !== 0 ? $cmp : (($b['score'] ?? 0.0) <=> ($a['score'] ?? 0.0));
        });

        return [$chunks, [
            'pool_k' => $poolK,
            'pool_size' => count($chunks),
            'epsilon' => self::PRECEDENCE_EPSILON,
            'boosted' => $boosted,
        ]];
    }

    /**
     * The set of HR topics a chunk covers, by anchor-term presence (Correction-03).
     * Accent-insensitive, lowercased. Returns a map topic => true.
     *
     * @return array<string, true>
     */
    private function chunkTopics(string $content): array
    {
        $hay = $this->stripAccents(mb_strtolower($content));
        $topics = [];
        foreach (self::TOPIC_ANCHORS as $topic => $anchors) {
            foreach ($anchors as $anchor) {
                if (str_contains($hay, $this->stripAccents($anchor))) {
                    $topics[$topic] = true;
                    break;
                }
            }
        }

        return $topics;
    }

    /**
     * Vague "total días libres" aggregation detector (Correction-03, Fix 2).
     * Narrow by construction: requires BOTH a GENERIC leave phrase (not a single
     * named leave type) AND an explicit aggregation/total marker, so a concrete
     * single-topic question ("¿cuántas vacaciones tengo?", "¿qué permisos tengo?")
     * never trips it.
     */
    private function isVagueAggregationTotal(string $question): bool
    {
        $q = $this->stripAccents(mb_strtolower($question));

        $genericLeave = (bool) preg_match('/\bd[ií]as?\s+(libres|de\s+descanso|sin\s+trabajar|no\s+laborables)\b/u', $q)
            || (bool) preg_match('/\btiempo\s+libre\b/u', $q);

        $aggregation = (bool) preg_match('/\b(en\s+total|en\s+conjunto|en\s+su\s+conjunto|sumando|todos?\s+los\s+d[ií]as)\b/u', $q)
            || (bool) preg_match('/\btotal\b/u', $q);

        return $genericLeave && $aggregation;
    }

    /**
     * Merge retrieved chunks into the accumulator keyed by chunk id, keeping the
     * MAX score when the same chunk surfaces from more than one query.
     *
     * @param  array<int,array<string,mixed>>  $byChunkId
     * @param  list<array<string,mixed>>  $chunks
     */
    private function mergeChunks(array &$byChunkId, array $chunks): void
    {
        foreach ($chunks as $c) {
            $id = (int) ($c['id'] ?? 0);
            if ($id === 0) {
                continue;
            }
            if (! isset($byChunkId[$id]) || ($c['score'] ?? 0.0) > ($byChunkId[$id]['score'] ?? 0.0)) {
                $byChunkId[$id] = $c;
            }
        }
    }

    /** /retrieve that never throws — a failure yields an empty result (escalation). */
    private function safeRetrieve(array $params): array
    {
        try {
            return $this->ai->retrieve($params);
        } catch (\Throwable $e) {
            Log::warning('chat: /retrieve failed', ['detail' => $e->getMessage()]);

            return ['chunks' => [], 'eligible_total' => 0];
        }
    }

    /**
     * Order convenio / internal-ruling chunks BEFORE national_law, preserving the
     * retrieval-score order within each group.
     *
     * @param  list<array<string,mixed>>  $chunks
     * @return list<array<string,mixed>>
     */
    private function orderByAuthority(array $chunks): array
    {
        $governing = [];
        $baseline = [];
        foreach ($chunks as $c) {
            if (($c['authority_level'] ?? null) === 'national_law') {
                $baseline[] = $c;
            } else {
                $governing[] = $c;
            }
        }

        return array_merge($governing, $baseline);
    }

    /**
     * The text of every CITED chunk (for the figure-grounding pre-check).
     *
     * @param  list<array<string,mixed>>  $validCitations
     * @param  list<array<string,mixed>>  $chunks
     * @return list<string>
     */
    private function citedChunkTexts(array $validCitations, array $chunks): array
    {
        $byChunkId = collect($chunks)->keyBy(fn ($c) => (int) $c['id']);
        $texts = [];
        foreach ($validCitations as $cit) {
            $chunk = $byChunkId->get((int) $cit['chunk_id']);
            if ($chunk && isset($chunk['content'])) {
                $texts[] = (string) $chunk['content'];
            }
        }

        return $texts;
    }

    /**
     * Cited chunks (id + content + authority) for the /ground entailment call.
     *
     * @param  list<array<string,mixed>>  $validCitations
     * @param  list<array<string,mixed>>  $chunks
     * @return list<array{chunk_id:int, content:string, authority_level:?string}>
     */
    private function citedChunksForGrounding(array $validCitations, array $chunks): array
    {
        $byChunkId = collect($chunks)->keyBy(fn ($c) => (int) $c['id']);
        $out = [];
        foreach ($validCitations as $cit) {
            $chunk = $byChunkId->get((int) $cit['chunk_id']);
            if ($chunk && isset($chunk['content'])) {
                $out[] = [
                    'chunk_id' => (int) $cit['chunk_id'],
                    'content' => (string) $chunk['content'],
                    'authority_level' => $chunk['authority_level'] ?? ($cit['authority_level'] ?? null),
                ];
            }
        }

        return $out;
    }

    /**
     * Deterministic figure-grounding PRE-CHECK (Correction-01; now a pre-check
     * feeding the §5 entailment gate, not the gate itself). Extracts every
     * load-bearing figure (number + unit) and verifies each appears in at least
     * one cited chunk's text (digit or spelled-out Spanish form). Conservative:
     * a figure is "ungrounded" only when ENTIRELY absent; the action is escalate.
     *
     * @param  list<string>  $citedTexts
     * @return array{checked:bool, grounded:bool, figures:list<string>, ungrounded:list<string>}
     */
    private function checkFigureGrounding(string $answer, array $citedTexts): array
    {
        $unit = 'd[ií]as?|meses|mes|horas?|años?|semanas?|€|euros?';
        preg_match_all('/(\d[\d.,]*)\s*('.$unit.')/iu', $answer, $matches, PREG_SET_ORDER);

        if (empty($matches)) {
            return ['checked' => true, 'grounded' => true, 'figures' => [], 'ungrounded' => []];
        }

        $combined = implode(' ', $citedTexts);
        $digitHaystack = $this->normalizeDigits($combined);
        $wordHaystack = $this->stripAccents(mb_strtolower($combined));

        $figures = [];
        $ungrounded = [];
        foreach ($matches as $m) {
            $figure = trim($m[0]);
            $figures[] = $figure;
            $needle = $this->normalizeDigits($m[1]);

            if ($needle === '') {
                $ungrounded[] = $figure;

                continue;
            }

            $groundedAsDigit = (bool) preg_match('/(?<!\d)'.preg_quote($needle, '/').'(?!\d)/', $digitHaystack);

            $groundedAsWord = false;
            if (! $groundedAsDigit && ctype_digit($needle)) {
                $int = (int) $needle;
                if ($int >= 0 && $int <= 100) {
                    foreach ($this->spanishCardinals($int) as $word) {
                        if (str_contains($wordHaystack, $word)) {
                            $groundedAsWord = true;
                            break;
                        }
                    }
                }
            }

            if (! $groundedAsDigit && ! $groundedAsWord) {
                $ungrounded[] = $figure;
            }
        }

        return [
            'checked' => true,
            'grounded' => count($ungrounded) === 0,
            'figures' => $figures,
            'ungrounded' => $ungrounded,
        ];
    }

    /** Strip thousands separators so "1.234" and "1234" compare equal; keep digits. */
    private function normalizeDigits(string $s): string
    {
        return (string) preg_replace('/(?<=\d)\.(?=\d{3}\b)/', '', $s);
    }

    /** Lowercase, accent-stripped form for word matching (treinta = treinta). */
    private function stripAccents(string $s): string
    {
        return strtr($s, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u',
        ]);
    }

    /**
     * Accent-stripped Spanish cardinal word forms for an integer 0–100.
     *
     * @return list<string>
     */
    private function spanishCardinals(int $n): array
    {
        static $units = [
            0 => 'cero', 1 => 'uno', 2 => 'dos', 3 => 'tres', 4 => 'cuatro', 5 => 'cinco',
            6 => 'seis', 7 => 'siete', 8 => 'ocho', 9 => 'nueve', 10 => 'diez', 11 => 'once',
            12 => 'doce', 13 => 'trece', 14 => 'catorce', 15 => 'quince', 16 => 'dieciseis',
            17 => 'diecisiete', 18 => 'dieciocho', 19 => 'diecinueve', 20 => 'veinte',
            21 => 'veintiuno', 22 => 'veintidos', 23 => 'veintitres', 24 => 'veinticuatro',
            25 => 'veinticinco', 26 => 'veintiseis', 27 => 'veintisiete', 28 => 'veintiocho',
            29 => 'veintinueve',
        ];
        static $tens = [
            30 => 'treinta', 40 => 'cuarenta', 50 => 'cincuenta', 60 => 'sesenta',
            70 => 'setenta', 80 => 'ochenta', 90 => 'noventa',
        ];

        if ($n === 100) {
            return ['cien', 'ciento'];
        }
        if (isset($units[$n])) {
            return match ($n) {
                1 => ['uno', 'un', 'una'],
                21 => ['veintiuno', 'veintiun', 'veintiuna'],
                default => [$units[$n]],
            };
        }
        if (isset($tens[$n])) {
            return [$tens[$n]];
        }
        $tensPart = $tens[intdiv($n, 10) * 10] ?? null;
        $unitPart = $units[$n % 10] ?? null;
        if ($tensPart !== null && $unitPart !== null) {
            $forms = [$tensPart.' y '.$unitPart];
            if ($n % 10 === 1) {
                $forms[] = $tensPart.' y un';
            }

            return $forms;
        }

        return [];
    }

    /**
     * Map validated citations (chunk_id → real document title + page) for display
     * and persistence. page_number = the chunk's page_from (canonical).
     *
     * @param  list<array<string,mixed>>  $validCitations
     * @param  list<array<string,mixed>>  $chunks
     * @return list<array<string,mixed>>
     */
    private function resolveCitations(array $validCitations, array $chunks): array
    {
        $byChunkId = collect($chunks)->keyBy(fn ($c) => (int) $c['id']);
        $docIds = collect($validCitations)->pluck('document_id')->unique()->all();
        $docs = Document::with(['convenio:id,name'])->whereIn('id', $docIds)->get()->keyBy('id');

        $out = [];
        foreach ($validCitations as $cit) {
            $chunkId = (int) $cit['chunk_id'];
            $chunk = $byChunkId->get($chunkId);
            $doc = $docs->get($cit['document_id']);
            $pageFrom = $cit['page_from'] ?? ($chunk['page_from'] ?? null);
            $pageTo = $cit['page_to'] ?? ($chunk['page_to'] ?? null);
            $content = (string) ($chunk['content'] ?? '');

            $out[] = [
                'chunk_id' => $chunkId,
                'document_id' => $cit['document_id'],
                'document_uuid' => $doc?->uuid,
                'document_title' => $doc?->title,
                'authority_level' => $doc?->authority_level ?? ($cit['authority_level'] ?? null),
                'page_from' => $pageFrom,
                'page_to' => $pageTo,
                'page_number' => $pageFrom,
                'snippet' => trim(mb_substr(preg_replace('/\s+/', ' ', $content) ?? '', 0, 160)),
            ];
        }

        return $out;
    }

    /** Most-recent session within the window, else a new one (Sprint 5 adds management). */
    private function resolveSession(Employee $employee, ?string $sessionUuid): ChatSession
    {
        if ($sessionUuid) {
            $existing = ChatSession::where('uuid', $sessionUuid)->where('employee_id', $employee->id)->first();
            if ($existing) {
                return $existing;
            }
        }

        $windowHours = (int) config('hr.session_window_hours', 24);
        $recent = ChatSession::where('employee_id', $employee->id)
            ->where('last_activity_at', '>=', now()->subHours($windowHours))
            ->orderByDesc('last_activity_at')
            ->first();

        return $recent ?? ChatSession::create([
            'employee_id' => $employee->id,
            'started_at' => now(),
            'last_activity_at' => now(),
        ]);
    }

    /**
     * Persist the full turn in ONE transaction (hr-backend owns ALL writes):
     * user message, assistant message, citations (answer turns), trace (always),
     * escalation_card (escalate turns ONLY — never on a category pick). Returns
     * the response payload.
     *
     * @param  list<array<string,mixed>>  $citations
     * @param  array<string,mixed>  $trace
     * @param  string  $outcome  'answer' | 'escalate' | 'needs_category'
     * @param  list<array<string,mixed>>  $categories
     * @return array<string,mixed>
     */
    private function persistTurn(
        ChatSession $session,
        Employee $employee,
        string $question,
        string $answer,
        array $citations,
        array $trace,
        string $outcome,
        ?string $escalationReason,
        array $categories = [],
    ): array {
        $escalate = $outcome === 'escalate';

        return DB::transaction(function () use ($session, $employee, $question, $answer, $citations, $trace, $outcome, $escalate, $escalationReason, $categories) {
            $session->forceFill(['last_activity_at' => now()])->save();

            $userMessage = ChatMessage::create([
                'session_id' => $session->id,
                'role' => 'user',
                'content' => $question,
            ]);

            $assistantMessage = ChatMessage::create([
                'session_id' => $session->id,
                'role' => 'assistant',
                'content' => $answer,
            ]);

            foreach ($citations as $c) {
                MessageCitation::create([
                    'message_id' => $assistantMessage->id,
                    'document_id' => $c['document_id'],
                    'chunk_id' => $c['chunk_id'] ?? null,
                    'page_number' => $c['page_number'] ?? null,
                ]);
            }

            MessageTrace::create([
                'message_id' => $assistantMessage->id,
                'trace' => $trace,
            ]);

            $card = null;
            if ($escalate) {
                $card = EscalationCard::create([
                    'chat_session_id' => $session->id,
                    'source_message_id' => $userMessage->id,
                    'employee_id' => $employee->id,
                    'reason' => $escalationReason ?? 'low_confidence',
                    'status' => 'new',
                ]);
            }

            return [
                'session_uuid' => $session->uuid,
                'message_id' => $assistantMessage->id,
                'outcome' => $outcome,
                'escalated' => $escalate,
                'escalation_reason' => $escalationReason,
                'escalation_uuid' => $card?->uuid,
                'answer' => $answer,
                'citations' => $outcome === 'answer' ? $citations : [],
                'categories' => $categories,
                'authority_used' => $trace['floor_decision']['authority_used'] ?? [],
                'trace' => $trace,
            ];
        });
    }
}
