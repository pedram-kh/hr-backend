<?php

namespace App\Services\Answer;

use App\Models\AnswerModelSetting;
use App\Models\Employee;
use App\Models\ReferenceFact;
use App\Services\ChatService;
use App\Services\ExtractionClient;
use App\Services\GroundingService;
use App\Services\GuardrailPolicy;
use App\Services\ReferenceFactAnswerService;
use App\Support\TopicLexicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Sprint 13, build step 1 (plan.md §B.1) — `App\Services\Answer\ReferenceFactPath`,
 * extracted VERBATIM from `ChatService` (`answerReferenceFact`,
 * `composeFactWithProse`, `detectFactProseConflict`, `extractFiguresByUnit`,
 * `canonicalUnit`, `compositionCitedForGrounding`, `compositionCitations` —
 * `ChatService.php:433-872`, pre-refactor line numbers): the reference-fact
 * Phase 1 (bare quote) and Phase 2 (composed with governing convenio prose)
 * answer paths.
 *
 * Two tiny private helpers (`normalizeDigits`, `stripAccents`) and
 * `applyToneToSynthesisQuestion` are DUPLICATED here rather than shared with
 * `ProsePath` — each was a single shared private method on the old
 * `ChatService` used by both this path's figure-conflict detector and
 * `ProsePath`'s figure-grounding pre-check / tone wrapper; a private method
 * can't be shared across two sibling classes without a new abstraction the
 * plan doesn't call for, so each class gets its own byte-identical copy
 * (pure, stateless functions — zero behavior risk).
 */
class ReferenceFactPath
{
    public function __construct(
        private readonly ReferenceFactAnswerService $referenceFactAnswer,
        private readonly GuardrailPolicy $policy,
        private readonly RetrievalUnion $retrievalUnion,
        private readonly ExtractionClient $ai,
        private readonly GroundingService $grounding,
    ) {}

    /**
     * The reference-fact answer path (Sprint 7c Phase 1, ADR-0023) — the salary
     * sibling. Quotes the EXACT verified fact, cites the source doc with
     * `chunk_id = null` at `structured_reference`, records the `reference_fact`
     * trace block + `floor_decision.path = "reference_fact"`, and SKIPS /ground
     * (a quoted verified value — nothing generated to entail). Only a `verified`
     * fact answers; a coverage gap escalates `reference_fact_coverage_gap`. No
     * Check A/B (structured-grounded, exactly like salary's `path:"salary_sql"`).
     *
     * @param  array{topic_id:int, topic_name:string, matched_topic_names:list<string>}  $detection
     * @param  array<string,mixed>  $trace
     */
    public function handle(Employee $employee, string $question, array $detection, Carbon $asOfDate, array $trace): TurnOutcome
    {
        // The deterministic pre-check short-circuits the LLM router (parallels the
        // deterministic_salary source); record it for the audit trail.
        $trace['router_decision'] = [
            'label' => 'reference_fact',
            'confidence' => 1.0,
            'source' => 'deterministic_reference_fact',
            'subqueries' => [],
            'model' => null,
            'note' => 'matched deterministic reference-fact pre-check (topic: '.$detection['topic_name'].')',
            'cross_path' => false,
            'trace_fragment' => ['matched_topic_names' => $detection['matched_topic_names']],
        ];

        $result = $this->referenceFactAnswer->answer($employee, $detection['topic_id'], $asOfDate);
        $trace['reference_fact'] = $result['reference_fact'];

        if ($result['outcome'] === ReferenceFactAnswerService::OUTCOME_ANSWER) {
            // --- Phase 2 (ADR-0023): compose with governing convenio prose -------
            // If governing convenio/ruling prose on the topic is present (Q5: it
            // clears the existing Check A retrieval floor), compose ONE grounded
            // answer from both typed sources (the convenio governing, the fact at
            // structured_reference) instead of quoting the bare value. A same-point
            // conflict escalates (never blends). When no governing prose is present
            // — or the answer model isn't configured — this returns null and we
            // fall back to the Phase 1 quoted value (skip /ground). Phase 2 is thus
            // purely additive on top of Phase 1.
            $composed = $this->composeFactWithProse($employee, $question, $result, $asOfDate, $trace);
            if ($composed !== null) {
                return $composed;
            }

            // --- Phase 1 fallback: quote the exact verified value (skip /ground) -
            $trace['floor_decision'] = [
                'path' => 'reference_fact',
                'outcome' => 'answer',
                'escalation_reason' => null,
                'authority_used' => [ReferenceFact::AUTHORITY_LEVEL],
                'note' => 'exact verified reference fact (fact_id '.($result['reference_fact']['fact_id'] ?? '?').') — quoted value, /ground skipped',
            ];

            return new TurnOutcome('answer', $result['answer'], $result['citations'], $trace, null);
        }

        // No usable VERIFIED in-scope in-validity fact → escalate (only verified
        // answers; never quote an unverified/out-of-validity/guessed-group fact).
        $trace['floor_decision'] = [
            'path' => 'reference_fact',
            'outcome' => 'escalate',
            'escalation_reason' => $result['escalation_reason'],
            'authority_used' => [ReferenceFact::AUTHORITY_LEVEL],
            'note' => $result['reference_fact']['note'] ?? 'reference fact coverage gap',
        ];

        return new TurnOutcome('escalate', $result['answer'], [], $trace, $result['escalation_reason']);
    }

    /**
     * Phase 2 (ADR-0023) — compose the verified fact WITH governing convenio prose
     * into ONE grounded answer, or return null to fall back to the Phase 1 quote.
     *
     * Composition reuses /synthesise + /ground UNCHANGED: the fact is handed in as
     * one MORE typed, authority-labelled source (Q7 — source_type=reference_fact,
     * chunk_id=null, structured_reference), ordered BELOW the convenio by the
     * existing precedence rule. The convenio always governs; a genuine fact-vs-
     * convenio same-point conflict escalates (`conflict`), never blends. Because a
     * composed answer is GENERATED (not a quoted value), it MUST /ground — the
     * skip/ground line is drawn by construction (P1 quotes → skips; P2 generates →
     * grounds).
     *
     * Returns null (→ Phase 1 quote) when: no answer model configured, OR no
     * governing convenio/ruling prose on the topic clears Check A (Q5).
     *
     * @param  array{outcome:string, answer:string, citations:list<array<string,mixed>>, escalation_reason:?string, reference_fact:array<string,mixed>}  $factResult
     * @param  array<string,mixed>  $trace
     */
    private function composeFactWithProse(Employee $employee, string $question, array $factResult, Carbon $asOfDate, array $trace): ?TurnOutcome
    {
        $settings = AnswerModelSetting::current();
        $decryptedKey = $settings->isConfigured() ? $settings->decryptKey() : null;
        if ($decryptedKey === null) {
            return null; // nothing to synthesise with → Phase 1 quoted value
        }

        // The fact source for synthesis/grounding (from the Phase 1 result).
        $factCitation = $factResult['citations'][0] ?? null;
        $factValue = (string) ($factResult['reference_fact']['value'] ?? '');
        if ($factCitation === null || $factValue === '') {
            return null; // a fact with no citable source can't enter the composed set
        }

        // Recall-hardened retrieval (reuse the prose union; single-topic → no
        // subqueries). Sprint 10b (ADR-0033): decomposedQueries defaults to []
        // and is deliberately not wired here — the reference-fact path never
        // calls the router (short-circuits before it, above), so there is no
        // decomposition to thread through (plan.md §B.2 scope note).
        $union = $this->retrievalUnion->retrieveUnion($question, [], $employee->convenio_id, $asOfDate->toDateString());
        $chunks = $union['chunks'];

        // Governing convenio/ruling prose ON THE TOPIC (Q5: composition rides Check A).
        $questionTopics = $this->chunkTopics($question);
        $governingOnTopic = array_values(array_filter($chunks, function ($c) use ($questionTopics) {
            if (! in_array($c['authority_level'] ?? null, ['official_convenio', 'internal_hr_ruling'], true)) {
                return false;
            }

            return array_intersect_key($this->chunkTopics((string) ($c['content'] ?? '')), $questionTopics) !== [];
        }));
        $governingTop = empty($governingOnTopic) ? 0.0 : (float) collect($governingOnTopic)->max('score');
        $retrievalFloor = $this->policy->retrievalFloor();

        if ($governingTop < $retrievalFloor) {
            unset($decryptedKey);

            return null; // no governing convenio prose on the topic → Phase 1 quote
        }

        // --- This is a composition turn -----------------------------------------
        $trace['composition'] = [
            'detected' => true,
            'governing_on_topic_chunks' => count($governingOnTopic),
            'governing_top_score' => round($governingTop, 6),
            'check_a' => true,
        ];

        // Same-point conflict (escalate-not-blend): the fact's load-bearing figures
        // vs the governing convenio chunks' figures on the same unit. A same-unit /
        // different-value disagreement is a genuine conflict → escalate (the
        // convenio governs; never blend or silently prefer the fact). Deterministic
        // and conservative (it errs toward escalation, the safe direction).
        $conflict = $this->detectFactProseConflict($factValue, $governingOnTopic);
        if ($conflict['conflict']) {
            unset($decryptedKey);
            $trace['composition']['conflict'] = $conflict;
            $trace['floor_decision'] = [
                'path' => 'reference_fact_composition',
                'outcome' => 'escalate',
                'escalation_reason' => 'conflict',
                'authority_used' => ['official_convenio', ReferenceFact::AUTHORITY_LEVEL],
                'note' => 'fact vs convenio same-point conflict ('.$conflict['unit'].': fact '.implode('/', $conflict['fact_values']).' vs convenio '.implode('/', $conflict['prose_values']).') — escalated, never blended (convenio governs)',
            ];

            return new TurnOutcome('escalate', ChatService::COMPOSITION_CONFLICT_MESSAGE, [], $trace, 'conflict');
        }

        // Ordered typed source list (precedence): governing convenio/ruling chunks
        // first, then the fact at structured_reference, then the national_law
        // baseline. The fact is just one more typed source carrying its label.
        $orderedChunks = $this->retrievalUnion->orderByAuthority($chunks);
        $providedChunkIds = collect($orderedChunks)->pluck('id')->map(fn ($v) => (int) $v)->all();

        $factSource = [
            'chunk_id' => null,
            'source_type' => 'reference_fact',
            'document_id' => (int) $factCitation['document_id'],
            'page_from' => null,
            'page_to' => null,
            'content' => $factValue,
            'score' => 1.0,
            'authority_level' => ReferenceFact::AUTHORITY_LEVEL,
        ];

        $synthesisSources = [];
        $factInserted = false;
        foreach ($orderedChunks as $c) {
            // Insert the structured_reference fact right after the governing
            // convenio/ruling block and before the national_law baseline.
            if (! $factInserted && ($c['authority_level'] ?? null) === 'national_law') {
                $synthesisSources[] = $factSource;
                $factInserted = true;
            }
            $synthesisSources[] = [
                'chunk_id' => (int) $c['id'],
                'source_type' => 'chunk',
                'document_id' => $c['document_id'],
                'page_from' => $c['page_from'] ?? null,
                'page_to' => $c['page_to'] ?? null,
                'content' => $c['content'],
                'score' => $c['score'] ?? 0.0,
                'authority_level' => $c['authority_level'] ?? null,
            ];
        }
        if (! $factInserted) {
            $synthesisSources[] = $factSource; // no national_law baseline present
        }

        $providerConfig = [
            'provider' => config('services.hr_ai.answer_provider', 'claude'),
            'model' => config('services.hr_ai.answer_model'),
            'endpoint' => config('services.hr_ai.answer_endpoint'),
        ];

        $synth = $this->ai->synthesise($this->applyToneToSynthesisQuestion($question), $synthesisSources, $decryptedKey, $providerConfig);

        if (isset($synth['error'])) {
            Log::warning('chat: composition synthesis provider failure', ['error' => $synth['error']]);
            unset($decryptedKey);
            $trace['composition']['synthesis_error'] = $synth['error'];
            $trace['floor_decision'] = [
                'path' => 'reference_fact_composition',
                'outcome' => 'escalate',
                'escalation_reason' => 'low_confidence',
                'authority_used' => ['official_convenio', ReferenceFact::AUTHORITY_LEVEL],
                'note' => 'composition provider error',
            ];

            return new TurnOutcome('escalate', ChatService::ESCALATION_MESSAGE, [], $trace, 'low_confidence');
        }

        $answer = trim((string) ($synth['answer'] ?? ''));
        $confidence = (float) ($synth['confidence'] ?? 0.0);
        $authorityUsed = $synth['authority_used'] ?? [];

        // Check B: every cited source was provided (reject hallucinated citations).
        // A citation is valid when it is a provided chunk OR the fact source.
        $validCitations = [];
        foreach (($synth['citations'] ?? []) as $cit) {
            $isFact = ($cit['source_type'] ?? 'chunk') === 'reference_fact'
                || (($cit['chunk_id'] ?? null) === null && (int) ($cit['document_id'] ?? 0) === (int) $factCitation['document_id']);
            if ($isFact) {
                $validCitations[] = ['source_type' => 'reference_fact'] + $cit;

                continue;
            }
            $cid = isset($cit['chunk_id']) ? (int) $cit['chunk_id'] : null;
            if ($cid !== null && in_array($cid, $providedChunkIds, true)) {
                $validCitations[] = ['source_type' => 'chunk'] + $cit;
            }
        }
        $checkB = count($validCitations) >= 1 && $answer !== '';

        // The composed answer is GENERATED → it MUST /ground (each substantive
        // claim entailed against ITS cited source: the fact's claim vs the fact,
        // a prose claim vs its chunk). Cited sources carry source_type so the fact
        // is entailed against its own quoted value (chunk_id=null).
        $groundingResult = null;
        $note = null;
        if (! $checkB) {
            $note = 'no valid citations (Check B failed)';
        } else {
            $citedForGround = $this->compositionCitedForGrounding($validCitations, $chunks, $factValue, (int) $factCitation['document_id']);
            $groundingResult = $this->grounding->check($question, $answer, $citedForGround, $decryptedKey, $providerConfig);
            $note = $groundingResult['grounded'] ? null : 'ungrounded claim in composed answer (per-claim entailment gate)';
        }
        unset($decryptedKey);

        $grounded = $groundingResult !== null && $groundingResult['grounded'];
        $decisionPass = $checkB && $grounded;

        $trace['synthesis'] = [
            'provider' => $providerConfig['provider'],
            'model' => $providerConfig['model'],
            'citation_count' => count($validCitations),
            'confidence' => $confidence,
            'authority_used' => $authorityUsed,
            'composition' => true,
            'trace_fragment' => $synth['trace_fragment'] ?? [],
        ];
        $trace['floor_decision'] = [
            'path' => 'reference_fact_composition',
            'check_b_citations' => $checkB,
            'grounding' => $groundingResult === null
                ? ['checked' => false, 'reason' => 'short-circuited before /ground (Check B failed)']
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
            'note' => $decisionPass ? 'composed fact+convenio answer (convenio governs), grounded' : $note,
        ];

        if (! $decisionPass) {
            return new TurnOutcome('escalate', ChatService::ESCALATION_MESSAGE, [], $trace, 'low_confidence');
        }

        // Citation set = the cited convenio chunks (resolved) + the fact citation
        // (chunk_id=null) when the fact was cited.
        $citations = $this->compositionCitations($validCitations, $chunks, $factCitation);

        return new TurnOutcome('answer', $answer, $citations, $trace, null);
    }

    /**
     * Detect a same-point conflict between the fact's value and the governing
     * convenio prose: a unit (días/meses/horas/…) for which BOTH carry a figure
     * but share NO value. Conservative — only fires on a clear numeric
     * disagreement on the SAME unit (the safe escalate-not-blend direction).
     *
     * @param  list<array<string,mixed>>  $governingChunks
     * @return array{conflict:bool, unit:?string, fact_values:list<string>, prose_values:list<string>}
     */
    private function detectFactProseConflict(string $factValue, array $governingChunks): array
    {
        $factFigs = $this->extractFiguresByUnit($factValue);
        if ($factFigs === []) {
            return ['conflict' => false, 'unit' => null, 'fact_values' => [], 'prose_values' => []];
        }

        $proseFigs = [];
        foreach ($governingChunks as $c) {
            foreach ($this->extractFiguresByUnit((string) ($c['content'] ?? '')) as $unit => $vals) {
                $proseFigs[$unit] = array_unique(array_merge($proseFigs[$unit] ?? [], $vals));
            }
        }

        foreach ($factFigs as $unit => $factVals) {
            $proseVals = $proseFigs[$unit] ?? [];
            if ($proseVals !== [] && array_intersect($factVals, $proseVals) === []) {
                return [
                    'conflict' => true,
                    'unit' => $unit,
                    'fact_values' => array_values($factVals),
                    'prose_values' => array_values($proseVals),
                ];
            }
        }

        return ['conflict' => false, 'unit' => null, 'fact_values' => [], 'prose_values' => []];
    }

    /**
     * Extract figures grouped by canonical unit (días/meses/horas/años/semanas/€)
     * from text. Values are digit-normalized so "1.234" == "1234".
     *
     * @return array<string, list<string>>
     */
    private function extractFiguresByUnit(string $text): array
    {
        $unitPattern = 'd[ií]as?|meses|mes|horas?|años?|anos?|semanas?|€|euros?';
        preg_match_all('/(\d[\d.,]*)\s*('.$unitPattern.')/iu', $text, $matches, PREG_SET_ORDER);

        $byUnit = [];
        foreach ($matches as $m) {
            $value = $this->normalizeDigits($m[1]);
            $unit = $this->canonicalUnit($m[2]);
            if ($value === '' || $unit === '') {
                continue;
            }
            $byUnit[$unit][] = $value;
        }

        return array_map(fn ($v) => array_values(array_unique($v)), $byUnit);
    }

    /** Normalize a matched unit token to a canonical key (dia/mes/hora/ano/semana/euro). */
    private function canonicalUnit(string $raw): string
    {
        $u = $this->stripAccents(mb_strtolower(trim($raw)));

        return match (true) {
            str_starts_with($u, 'dia') => 'dia',
            $u === 'mes' || $u === 'meses' => 'mes',
            str_starts_with($u, 'hora') => 'hora',
            str_starts_with($u, 'ano') => 'ano',
            str_starts_with($u, 'semana') => 'semana',
            $u === '€' || str_starts_with($u, 'euro') => 'euro',
            default => '',
        };
    }

    /**
     * Cited sources for the composition /ground call: each cited convenio chunk
     * (id + content + authority) plus the fact (chunk_id=null + its quoted value +
     * source_type=reference_fact), so the fact's claim is entailed against the fact.
     *
     * @param  list<array<string,mixed>>  $validCitations
     * @param  list<array<string,mixed>>  $chunks
     * @return list<array<string,mixed>>
     */
    private function compositionCitedForGrounding(array $validCitations, array $chunks, string $factValue, int $factDocumentId): array
    {
        $byChunkId = collect($chunks)->keyBy(fn ($c) => (int) $c['id']);
        $out = [];
        foreach ($validCitations as $cit) {
            if (($cit['source_type'] ?? 'chunk') === 'reference_fact') {
                $out[] = [
                    'chunk_id' => null,
                    'source_type' => 'reference_fact',
                    'content' => $factValue,
                    'authority_level' => ReferenceFact::AUTHORITY_LEVEL,
                ];

                continue;
            }
            $chunk = $byChunkId->get((int) $cit['chunk_id']);
            if ($chunk && isset($chunk['content'])) {
                $out[] = [
                    'chunk_id' => (int) $cit['chunk_id'],
                    'source_type' => 'chunk',
                    'content' => (string) $chunk['content'],
                    'authority_level' => $chunk['authority_level'] ?? ($cit['authority_level'] ?? null),
                ];
            }
        }

        return $out;
    }

    /**
     * Build the composed citation set IN THE MODEL'S CITATION ORDER — the backend
     * renumbers the in-text [Fuente N] markers to the cited-subset order (§7), so
     * the displayed list must preserve that exact order for the 1:1 marker↔source
     * mapping (a fact and convenio chunks interleave by however the model cited
     * them). Each entry resolves to the fact citation (chunk_id=null,
     * is_reference_fact) or a convenio chunk citation (title + page).
     *
     * @param  list<array<string,mixed>>  $validCitations
     * @param  list<array<string,mixed>>  $chunks
     * @param  array<string,mixed>  $factCitation
     * @return list<array<string,mixed>>
     */
    private function compositionCitations(array $validCitations, array $chunks, array $factCitation): array
    {
        $out = [];
        foreach ($validCitations as $cit) {
            if (($cit['source_type'] ?? 'chunk') === 'reference_fact') {
                $out[] = $factCitation; // chunk_id=null, is_reference_fact

                continue;
            }
            // resolveCitations preserves input order; a single-element call keeps
            // this chunk citation in its model-cited position.
            $resolved = $this->retrievalUnion->resolveCitations([$cit], $chunks);
            if ($resolved !== []) {
                $out[] = $resolved[0];
            }
        }

        return $out;
    }

    /** Delegates to {@see RetrievalUnion::chunkTopics()} — see the class docblock for why this isn't a shared private method. */
    private function chunkTopics(string $content): array
    {
        return TopicLexicon::matchTopicKeys($content);
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
}
