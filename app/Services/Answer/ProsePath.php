<?php

namespace App\Services\Answer;

use App\Models\Employee;
use App\Services\ChatService;
use App\Services\ExtractionClient;
use App\Services\GroundingService;
use App\Services\GuardrailPolicy;
use App\Support\CorpusCoverageService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Sprint 13, build step 1 (plan.md §B.1) — `App\Services\Answer\ProsePath`,
 * extracted VERBATIM from `ChatService` (`answerProse`, `stampFallback`,
 * `isVagueAggregationTotal`, `applyToneToSynthesisQuestion`,
 * `citedChunkTexts`, `citedChunksForGrounding`, `checkFigureGrounding`,
 * `normalizeDigits`, `stripAccents`, `spanishCardinals` —
 * `ChatService.php:884-1700` minus the `RetrievalUnion`-owned methods
 * carved out of that same span, pre-refactor line numbers): the
 * aggregation guard, the Sprint 10a Estatuto-fallback classification,
 * Check A, synthesis, Check B, the figure-guard pre-check, and the
 * per-claim entailment gate (`/ground`).
 *
 * `applyToneToSynthesisQuestion`, `normalizeDigits`, `stripAccents` are
 * duplicated (byte-identical) rather than shared with `ReferenceFactPath` —
 * see that class's docblock for why.
 */
class ProsePath
{
    public function __construct(
        private readonly GuardrailPolicy $policy,
        private readonly CorpusCoverageService $coverage,
        private readonly RetrievalUnion $retrievalUnion,
        private readonly ExtractionClient $ai,
        private readonly GroundingService $grounding,
    ) {}

    /**
     * The prose answer path (recall-hardened retrieve → floor → synthesise →
     * A∧B → figure-guard pre-check → per-claim entailment gate). Returns the
     * turn outcome.
     *
     * @param  list<string>  $subqueries
     * @param  array<string,mixed>  $trace
     * @param  list<string>  $decomposedQueries  Sprint 10b (ADR-0033) — situational/
     *                                           colloquial retrieval rephrasings, [] on every turn until hr-ai returns one.
     * @param  bool  $protectMain  Sprint 13b — AGENT only, after a validated planner normalization: keep the literal
     *                             question's own top-10 through the synthesis cap ({@see RetrievalUnion::retrieveUnion()}).
     */
    public function handle(Employee $employee, string $question, array $subqueries, Carbon $asOfDate, ?string $decryptedKey, array $trace, array $decomposedQueries = [], bool $protectMain = false): TurnOutcome
    {
        // Effective floors = stricter_of(hardcoded baseline, admin override),
        // computed inside GuardrailPolicy (Sprint 6, ADR-0019). The caller never
        // sees a raw admin value — a raised floor escalates more (safer); it can
        // never drop below the config/hr.php baseline.
        $retrievalFloor = $this->policy->retrievalFloor();
        $confidenceFloor = $this->policy->confidenceFloor();

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

            return new TurnOutcome('escalate', ChatService::AGGREGATION_MESSAGE, [], $trace, 'low_confidence');
        }

        // --- Sprint 10a (ADR-0032): the Estatuto fallback decision -------------
        // Deterministic, before retrieval, in hr-backend. The AI is not asked and
        // does not decide (ADR-0015/0016): this is a read of what is in the
        // corpus, not a judgement about the question.
        //
        // Until now an employee whose convenio has no retrievable prose still got
        // an answer — `include_national_law: true` quietly filled the gap with the
        // Estatuto and the answer read as if it were their own agreement. That is
        // fine when the convenio was never supplied and dangerous when it was:
        // an expired convenio generally stays in force under ultraactividad (ET
        // art. 86.4), so the national minimum can be strictly worse than what the
        // employee is actually owed.
        //
        // So the gap is classified, not just detected:
        //   never_ingested → answer from the Estatuto, labelled as such (below);
        //   expired_only   → escalate, never fall back (fails closed, D3);
        //   covered        → nothing changes, byte for byte.
        $fallback = false;
        if ($employee->convenio_id !== null) {
            $proseGap = $this->coverage->classifyProseGap((int) $employee->convenio_id);

            if ($proseGap === CorpusCoverageService::PROSE_EXPIRED_ONLY) {
                unset($decryptedKey);
                $trace['prose_gap'] = ['classification' => $proseGap]
                    + $this->coverage->proseGapEvidence((int) $employee->convenio_id);
                $trace['floor_decision'] = [
                    'retrieval_score_floor' => $retrievalFloor,
                    'answer_confidence_floor' => $confidenceFloor,
                    'outcome' => 'escalate',
                    'escalation_reason' => 'estatuto_fallback_gap',
                    'note' => 'convenio prose exists but is not retrievable — the Estatuto fallback is not allowed to substitute for it (ADR-0032)',
                ];

                return new TurnOutcome('escalate', ChatService::ESCALATION_MESSAGE, [], $trace, 'estatuto_fallback_gap');
            }

            $fallback = $proseGap === CorpusCoverageService::PROSE_NEVER_INGESTED;
            if ($fallback) {
                $trace['prose_gap'] = ['classification' => $proseGap, 'reason_code' => null];
            }
        }

        // Recall hardening (§6, resolved §9 F): one /retrieve for the question,
        // one per decomposed sub-query (compound questions — the Q10 fix), one
        // per decomposed_queries retrieval rephrasing (Sprint 10b, ADR-0033 —
        // situational/colloquial vocabulary joins the SAME union the same way),
        // plus a national-law-only pass (the silent-topic recall — the Art. 14 ET
        // miss). Union, dedupe by chunk_id keeping the max score. /retrieve is
        // unchanged.
        $union = $this->retrievalUnion->retrieveUnion($question, $subqueries, $employee->convenio_id, $asOfDate->toDateString(), $fallback, $decomposedQueries, $protectMain);
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
            $trace['floor_decision'] = self::stampFallback([
                'retrieval_score_floor' => $retrievalFloor,
                'answer_confidence_floor' => $confidenceFloor,
                'check_a_retrieval' => false,
                'outcome' => 'escalate',
                'escalation_reason' => 'low_confidence',
                'note' => $eligibleTotal === 0 ? 'no eligible chunks' : 'eligible chunks but all below retrieval floor',
            ], $fallback);

            return new TurnOutcome('escalate', ChatService::ESCALATION_MESSAGE, [], $trace, 'low_confidence');
        }

        // --- Answer model must be configured to synthesise ----------------------
        if ($decryptedKey === null) {
            $trace['synthesis'] = ['skipped' => 'answer_model_not_configured'];
            $trace['floor_decision'] = self::stampFallback([
                'retrieval_score_floor' => $retrievalFloor,
                'answer_confidence_floor' => $confidenceFloor,
                'check_a_retrieval' => true,
                'outcome' => 'escalate',
                'escalation_reason' => 'low_confidence',
                'note' => 'answer model not configured',
            ], $fallback);

            return new TurnOutcome('escalate', ChatService::ESCALATION_MESSAGE, [], $trace, 'low_confidence');
        }

        // --- Step 5: synthesise (convenio chunks ordered before national_law) ----
        $orderedChunks = $this->retrievalUnion->orderByAuthority($chunks);
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

        // Tone constraints (Sprint 6, ADR-0019) are injected into a SYNTHESIS-LOCAL
        // string ONLY — NEVER into $question, which must stay RAW for /ground
        // (the entailment gate) and the router. The preamble is clearly delimited
        // as style-only; it cannot unlock a gate (Check A is already passed; Check
        // B + the figure-guard + /ground are all downstream of and independent
        // from synthesis wording — a hostile tone still escalates an ungrounded
        // answer). The admin tone string is sanitized at write time too.
        $synthesisQuestion = $this->applyToneToSynthesisQuestion($question);

        // Slice 13c: with the model-knowledge sub-flag on, ask /synthesise to declare whether the sources answer the question
        // (the structured abstention flag). Flag off = the call is exactly what it was.
        $reportAbstention = $this->policy->generalLaneModelKnowledgeEnabled();
        $synth = $this->ai->synthesise($synthesisQuestion, $synthesisChunks, $decryptedKey, $providerConfig + ($reportAbstention ? ['report_abstention' => true] : []));

        if (isset($synth['error'])) {
            Log::warning('chat: synthesis provider failure', ['error' => $synth['error']]); // never logs the key
            unset($decryptedKey);
            $trace['synthesis'] = ['provider' => $providerConfig['provider'], 'model' => $providerConfig['model'], 'error' => $synth['error']];
            $trace['floor_decision'] = self::stampFallback([
                'retrieval_score_floor' => $retrievalFloor,
                'answer_confidence_floor' => $confidenceFloor,
                'check_a_retrieval' => true,
                'outcome' => 'escalate',
                'escalation_reason' => 'low_confidence',
                'note' => 'provider error',
            ], $fallback);

            return new TurnOutcome('escalate', ChatService::ESCALATION_MESSAGE, [], $trace, 'low_confidence');
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
        // Slice 13c: an abstention the model declared (or, with no flag from the model, the opening-sentence phrase) is an
        // abstention even when it cites a related source — it must never persist as the answer.
        $abstained = $reportAbstention && ($synth['abstained'] ?? false) === true;

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
        if ($reportAbstention) {
            $trace['synthesis']['abstention'] = ['abstained' => $abstained, 'by' => $synth['abstained_by'] ?? null];
        }

        // Gate order: A (already passed) ∧ B ∧ figure-guard pre-check ∧ entailment.
        // Each failure escalates (low_confidence) in the safe direction.
        $groundingResult = null;
        if ($abstained) {
            $note = 'synthesis abstained (flag)';
        } elseif (! $checkB) {
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

        $decisionPass = ! $abstained && $checkB && $figureGuard['grounded'] && ($groundingResult !== null && $groundingResult['grounded']);

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
        if ($abstained) {
            // Only present when it fired: a turn with no abstention keeps every trace byte it had.
            $floor['synthesis_abstained'] = ['flag' => true, 'by' => $synth['abstained_by'] ?? null];
        }
        $trace['floor_decision'] = self::stampFallback($floor, $fallback);

        if (! $decisionPass) {
            return new TurnOutcome('escalate', ChatService::ESCALATION_MESSAGE, [], $trace, 'low_confidence');
        }

        $citations = $this->retrievalUnion->resolveCitations($validCitations, $chunks);

        return new TurnOutcome('answer', $answer, $citations, $trace, null);
    }

    /**
     * Add `floor_decision.fallback` — and ONLY when the fallback actually fired
     * (Sprint 10a, ADR-0032).
     *
     * The key's absence is load-bearing, not cosmetic. Every existing trace
     * assertion in `Sprint7cAdditivityRegressionTest` compares `floor_decision`
     * against a byte-for-byte golden copy; a key added unconditionally — even
     * one set to `null` or `false` — breaks that comparison for every question
     * in the system and would end this sprint's additivity claim. So a normal
     * turn's `floor_decision` is untouched, and `T4` asserts exactly that.
     *
     * @param  array<string,mixed>  $floor
     * @return array<string,mixed>
     */
    private static function stampFallback(array $floor, bool $fallback): array
    {
        if ($fallback) {
            $floor['fallback'] = ChatService::FALLBACK_ESTATUTO_GAP;
        }

        return $floor;
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
     * Wrap the question with the admin tone/style preamble for the SYNTHESIS call
     * ONLY (Sprint 6, ADR-0019). Returns the raw question unchanged when no tone
     * is configured. The preamble is explicitly scoped to style/format and states
     * it cannot change what is answered or the citation/grounding rules — and even
     * if a hostile string slipped past the write-time sanitizer, every gate is
     * downstream of and independent from this wording (Check B + figure-guard +
     * /ground all run on the RAW question / in hr-backend). Tone styles; it can
     * never unlock a gate.
     */
    private function applyToneToSynthesisQuestion(string $question): string
    {
        $tone = $this->policy->toneConstraints();
        if ($tone === null) {
            return $question;
        }

        return '[INSTRUCCIONES DE ESTILO — afectan SOLO al tono y el formato de la '
            .'redacción, NO a qué se responde ni a las reglas de citación y '
            .'fundamentación de las FUENTES: '.trim($tone)."]\n\n".$question;
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
}
