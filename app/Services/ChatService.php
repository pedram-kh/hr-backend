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
 * The answer pipeline orchestrator (Sprint 2b-1). Single prose path, NO router
 * (2b-2). Everything here runs in hr-backend EXCEPT the two hr-ai calls
 * (/retrieve, /synthesise). hr-backend resolves scope deterministically, owns
 * the answer-or-escalate DECISION (legal weight), and owns ALL DB writes.
 *
 * Pipeline (architecture.md §2/§5, ADR-0007/0015):
 *   1. resolve scope (deterministic SQL — no LLM)
 *   2. guardrail baseline (deterministic; fires BEFORE any hr-ai call)
 *   3. /retrieve (2a primitive)
 *   4. pre-synthesis floor (Check A: top score ≥ RETRIEVAL_SCORE_FLOOR)
 *   5. /synthesise (Claude; convenio chunks ordered before national_law; the
 *      precedence rule is encoded in the prompt; key passed decrypted per call)
 *   6. answer-or-escalate decision (Check A AND Check B; Check C tiebreaker only)
 *   7. persist (session, messages, citations, trace incl. authority_used,
 *      escalation_card on escalate)
 */
class ChatService
{
    /** Surfaced to the employee on escalation (design-system voice). */
    public const ESCALATION_MESSAGE = 'No estoy seguro de la respuesta a esta pregunta, '
        .'así que la estoy pasando a una persona del equipo de Recursos Humanos.';

    public function __construct(private readonly ExtractionClient $ai, private readonly GuardrailService $guardrail) {}

    /**
     * Handle one employee turn end to end. Always persists both messages + a full
     * trace; creates an escalation_card on any escalate outcome.
     *
     * @return array<string,mixed> the response payload for the API/UI
     */
    public function handleMessage(Employee $employee, string $question, ?string $sessionUuid = null): array
    {
        $asOfDate = Carbon::today();
        $session = $this->resolveSession($employee, $sessionUuid);

        // --- Trace accumulator (strict superset of the 2a retrieval:probe shape) ---
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
            'router_decision' => null, // no router in 2b-1 (2b-2)
            'guardrail_check' => ['fired' => false, 'reason' => null, 'rule' => null],
        ];

        // --- Step 2: guardrail baseline (BEFORE any hr-ai call) -----------------
        $guard = $this->guardrail->check($question);
        if ($guard['fired']) {
            $trace['guardrail_check'] = $guard;
            $trace['floor_decision'] = [
                'retrieval_score_floor' => (float) config('hr.retrieval_score_floor'),
                'answer_confidence_floor' => (float) config('hr.answer_confidence_floor'),
                'outcome' => 'escalate',
                'escalation_reason' => $guard['reason'],
            ];

            return $this->persistTurn(
                session: $session,
                employee: $employee,
                question: $question,
                answer: self::ESCALATION_MESSAGE,
                citations: [],
                trace: $trace,
                escalate: true,
                escalationReason: $guard['reason'],
            );
        }

        // --- Step 3: retrieve (2a primitive) ------------------------------------
        try {
            $retrieval = $this->ai->retrieve([
                'query' => $question,
                'convenio_id' => $employee->convenio_id,
                'include_national_law' => true,
                'retrieval_status' => ['active'],
                'as_of_date' => $asOfDate->toDateString(),
                'k' => 8,
            ]);
        } catch (\Throwable $e) {
            Log::warning('chat: /retrieve failed', ['detail' => $e->getMessage()]);
            $retrieval = ['chunks' => [], 'eligible_total' => 0];
        }

        $chunks = $retrieval['chunks'] ?? [];
        $eligibleTotal = (int) ($retrieval['eligible_total'] ?? 0);
        $topScore = empty($chunks) ? 0.0 : (float) collect($chunks)->max('score');

        $trace['retrieval'] = [
            'eligible_total' => $eligibleTotal,
            'k_requested' => 8,
            'returned' => count($chunks),
            'top_score' => round($topScore, 6),
            'chunks' => collect($chunks)->map(fn ($c) => [
                'chunk_id' => $c['id'] ?? null,
                'document_id' => $c['document_id'] ?? null,
                'page_from' => $c['page_from'] ?? null,
                'page_to' => $c['page_to'] ?? null,
                'score' => $c['score'] ?? null,
                'authority_level' => $c['authority_level'] ?? null,
            ])->all(),
        ];

        $retrievalFloor = (float) config('hr.retrieval_score_floor');
        $confidenceFloor = (float) config('hr.answer_confidence_floor');

        // --- Step 4: pre-synthesis floor (Check A) ------------------------------
        // Distinguish "no eligible chunks" from "eligible but too weak" — both
        // escalate (low_confidence), and the trace above already records which.
        $checkA = $topScore >= $retrievalFloor;
        if (! $checkA) {
            $trace['floor_decision'] = [
                'retrieval_score_floor' => $retrievalFloor,
                'answer_confidence_floor' => $confidenceFloor,
                'check_a_retrieval' => false,
                'outcome' => 'escalate',
                'escalation_reason' => 'low_confidence',
                'note' => $eligibleTotal === 0 ? 'no eligible chunks' : 'eligible chunks but all below retrieval floor',
            ];

            return $this->persistTurn(
                session: $session,
                employee: $employee,
                question: $question,
                answer: self::ESCALATION_MESSAGE,
                citations: [],
                trace: $trace,
                escalate: true,
                escalationReason: 'low_confidence',
            );
        }

        // --- Step 5: synthesise -------------------------------------------------
        // Precedence: order convenio (and internal-ruling) chunks BEFORE
        // national_law (the Estatuto baseline), so the prompt sees the governing
        // sources first. Each chunk is labelled with its authority_level.
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

        $settings = AnswerModelSetting::current();
        if (! $settings->isConfigured()) {
            // No key set → cannot synthesise. Escalate honestly (not a guess).
            $trace['synthesis'] = ['skipped' => 'answer_model_not_configured'];
            $trace['floor_decision'] = [
                'retrieval_score_floor' => $retrievalFloor,
                'answer_confidence_floor' => $confidenceFloor,
                'check_a_retrieval' => true,
                'outcome' => 'escalate',
                'escalation_reason' => 'low_confidence',
                'note' => 'answer model not configured',
            ];

            return $this->persistTurn($session, $employee, $question, self::ESCALATION_MESSAGE, [], $trace, true, 'low_confidence');
        }

        // Decrypt the key for THIS call only — not bound beyond the call stack,
        // never logged, never persisted by hr-ai (ADR-0015).
        $decryptedKey = $settings->decryptKey();
        $providerConfig = [
            'provider' => config('services.hr_ai.answer_provider', 'claude'),
            'model' => config('services.hr_ai.answer_model'),
            'endpoint' => config('services.hr_ai.answer_endpoint'),
        ];

        $synth = $this->ai->synthesise($question, $synthesisChunks, $decryptedKey, $providerConfig);
        unset($decryptedKey); // best-effort: drop the plaintext as soon as the call returns

        // Provider failure (or hr-ai unavailable) → escalate (low_confidence).
        if (isset($synth['error'])) {
            Log::warning('chat: synthesis provider failure', ['error' => $synth['error']]); // never logs the key
            $trace['synthesis'] = ['provider' => $providerConfig['provider'], 'model' => $providerConfig['model'], 'error' => $synth['error']];
            $trace['floor_decision'] = [
                'retrieval_score_floor' => $retrievalFloor,
                'answer_confidence_floor' => $confidenceFloor,
                'check_a_retrieval' => true,
                'outcome' => 'escalate',
                'escalation_reason' => 'low_confidence',
                'note' => 'provider error',
            ];

            return $this->persistTurn($session, $employee, $question, self::ESCALATION_MESSAGE, [], $trace, true, 'low_confidence');
        }

        // --- Step 6: answer-or-escalate decision --------------------------------
        $answer = trim((string) ($synth['answer'] ?? ''));
        $grounding = $synth['grounding_signal'] ?? [];
        $confidence = (float) ($synth['confidence'] ?? 0.0);
        $authorityUsed = $synth['authority_used'] ?? [];

        // Check B (load-bearing): citations present AND every cited chunk_id was
        // in the provided set (reject hallucinated citations — defence in depth;
        // hr-ai already maps from the set, hr-backend re-validates).
        $validCitations = [];
        foreach (($synth['citations'] ?? []) as $cit) {
            $cid = isset($cit['chunk_id']) ? (int) $cit['chunk_id'] : null;
            if ($cid !== null && in_array($cid, $providedChunkIds, true)) {
                $validCitations[] = $cit;
            }
        }
        $checkB = count($validCitations) >= 1 && $answer !== '';

        // Check C is NOT a primary gate (LLM self-confidence is poorly calibrated;
        // a confidently-wrong external model is the ADR-0015 risk). It is recorded
        // and used ONLY as a tiebreaker — it can add caution but can NEVER, on its
        // own, pass an answer that Checks A/B did not already support.
        $confidenceBelowFloor = $confidence < $confidenceFloor;

        // Figure-grounding guard (Correction-01): a cheap, deterministic backstop to
        // Check B — NOT the full per-claim entailment grounding (that is 2b-2). For
        // each load-bearing figure in the answer (a number with a unit — N días/
        // meses/horas/años/semanas, or a currency amount) verify the SAME figure
        // appears in at least one CITED chunk's text. Conservative: fires only when a
        // figure is entirely absent from all cited chunks, and the action is escalate
        // (the safe direction) — never a silent edit. This catches the silent-convenio
        // fabrication (a confident "6 meses" cited to a chunk that never says it).
        $citedTexts = $this->citedChunkTexts($validCitations, $chunks);
        $figureGuard = $this->checkFigureGrounding($answer, $citedTexts);

        $decisionPass = $checkA && $checkB && $figureGuard['grounded']; // A AND B (+ figure backstop) are load-bearing

        $trace['synthesis'] = [
            'provider' => $providerConfig['provider'],
            'model' => $providerConfig['model'],
            'citation_count' => count($validCitations),
            'confidence' => $confidence,
            'grounding_signal' => $grounding,
            'authority_used' => $authorityUsed,
            'trace_fragment' => $synth['trace_fragment'] ?? [],
        ];

        if (! $decisionPass) {
            // Distinguish WHY we escalate: retrieval (A), citations (B), or the
            // figure-grounding backstop (a cited answer with an ungrounded figure).
            if (! $checkA) {
                $note = 'retrieval floor (Check A failed)';
            } elseif (! $checkB) {
                $note = 'no valid citations (Check B failed)';
            } else {
                $note = 'answer figure not grounded in cited chunk';
            }

            $trace['floor_decision'] = [
                'retrieval_score_floor' => $retrievalFloor,
                'answer_confidence_floor' => $confidenceFloor,
                'check_a_retrieval' => $checkA,
                'check_b_citations' => $checkB,
                'check_c_confidence_tiebreaker' => ['confidence' => $confidence, 'below_floor' => $confidenceBelowFloor, 'used_as_gate' => false],
                'figure_grounding' => $figureGuard,
                'authority_used' => $authorityUsed,
                'outcome' => 'escalate',
                'escalation_reason' => 'low_confidence',
                'note' => $note,
            ];

            return $this->persistTurn($session, $employee, $question, self::ESCALATION_MESSAGE, [], $trace, true, 'low_confidence');
        }

        // Passed A AND B (and the figure backstop) → surface the cited answer.
        $trace['floor_decision'] = [
            'retrieval_score_floor' => $retrievalFloor,
            'answer_confidence_floor' => $confidenceFloor,
            'check_a_retrieval' => true,
            'check_b_citations' => true,
            'check_c_confidence_tiebreaker' => ['confidence' => $confidence, 'below_floor' => $confidenceBelowFloor, 'used_as_gate' => false],
            'figure_grounding' => $figureGuard,
            'authority_used' => $authorityUsed,
            'outcome' => 'answer',
            'escalation_reason' => null,
        ];

        // Resolve citations to source document + page for display + persistence.
        $citations = $this->resolveCitations($validCitations, $chunks);

        return $this->persistTurn($session, $employee, $question, $answer, $citations, $trace, false, null);
    }

    /**
     * Order convenio / internal-ruling chunks BEFORE national_law, preserving the
     * retrieval-score order within each group (the precedence rule's mechanical
     * half — the semantic half lives in the synthesis prompt).
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
     * The text of every CITED chunk (for the figure-grounding guard), resolved
     * from the retrieval set by chunk_id.
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
     * Deterministic figure-grounding backstop to Check B (Correction-01; NOT the
     * full per-claim entailment grounding — that is 2b-2). Extracts every
     * load-bearing figure from the answer (a number immediately followed by a unit
     * — días/meses/horas/años/semanas or a currency marker) and verifies each
     * appears in at least one cited chunk's text. Conservative: a figure is
     * "ungrounded" only when it is ENTIRELY absent from all cited chunks; the
     * resulting action is always escalate (never a silent edit).
     *
     * @param  list<string>  $citedTexts
     * @return array{checked:bool, grounded:bool, figures:list<string>, ungrounded:list<string>}
     */
    private function checkFigureGrounding(string $answer, array $citedTexts): array
    {
        // number (optional thousands/decimals) + a unit token.
        $unit = 'd[ií]as?|meses|mes|horas?|años?|semanas?|€|euros?';
        preg_match_all('/(\d[\d.,]*)\s*('.$unit.')/iu', $answer, $matches, PREG_SET_ORDER);

        if (empty($matches)) {
            // No load-bearing figure to verify — the guard is a no-op (grounded).
            return ['checked' => true, 'grounded' => true, 'figures' => [], 'ungrounded' => []];
        }

        $combined = implode(' ', $citedTexts);
        $digitHaystack = $this->normalizeDigits($combined);
        // Legal text frequently spells small numbers as words (e.g. the Estatuto
        // says "treinta días", not "30 días"). Match those too so a correctly
        // grounded baseline figure is NOT flagged — the guard fires only when a
        // figure is absent in BOTH digit and word form.
        $wordHaystack = $this->stripAccents(mb_strtolower($combined));

        $figures = [];
        $ungrounded = [];
        foreach ($matches as $m) {
            $figure = trim($m[0]);
            $figures[] = $figure;
            $needle = $this->normalizeDigits($m[1]); // the numeric token only

            if ($needle === '') {
                $ungrounded[] = $figure;

                continue;
            }

            // (a) Digit form, bounded by non-digits so "31" ≠ inside "315".
            $groundedAsDigit = (bool) preg_match('/(?<!\d)'.preg_quote($needle, '/').'(?!\d)/', $digitHaystack);

            // (b) Spelled-out Spanish form for integers 0–100.
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
        // Remove a dot used as a thousands separator between digit groups.
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
     * Accent-stripped Spanish cardinal word forms for an integer 0–100, used by the
     * figure-grounding guard to recognise a figure spelled out in legal text.
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
            // 1/21 also appear apocopated ("un", "veintiun") before a noun.
            return match ($n) {
                1 => ['uno', 'un', 'una'],
                21 => ['veintiuno', 'veintiun', 'veintiuna'],
                default => [$units[$n]],
            };
        }
        if (isset($tens[$n])) {
            return [$tens[$n]];
        }
        // 31–99 not a round ten → "<tens> y <unit>" (and apocopated unit for x1).
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

    /** Most-recent session within the window, else a new one (Q-D; Sprint 5 adds management). */
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
            ->orderByDesc('last_activity_at') // ORDER BY ... DESC LIMIT 1 (Q-H: fine for 2b-1)
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
     * escalation_card (escalate turns). Returns the response payload.
     *
     * @param  list<array<string,mixed>>  $citations
     * @param  array<string,mixed>  $trace
     * @return array<string,mixed>
     */
    private function persistTurn(
        ChatSession $session,
        Employee $employee,
        string $question,
        string $answer,
        array $citations,
        array $trace,
        bool $escalate,
        ?string $escalationReason,
    ): array {
        return DB::transaction(function () use ($session, $employee, $question, $answer, $citations, $trace, $escalate, $escalationReason) {
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
                // Decide-and-queue: status = new, assigned_to = null. Board is Sprint 4.
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
                'message_id' => $assistantMessage->id, // chat_messages have no uuid (data-model §8)
                'escalated' => $escalate,
                'escalation_reason' => $escalationReason,
                'escalation_uuid' => $card?->uuid,
                'answer' => $answer,
                'citations' => $escalate ? [] : $citations,
                'authority_used' => $trace['floor_decision']['authority_used'] ?? [],
                // The structured "how I got here" trace, for the expandable UI view.
                // (It is also persisted to message_traces — this is the same object.)
                'trace' => $trace,
            ];
        });
    }
}
