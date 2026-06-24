<?php

namespace App\Services;

use App\Models\AnswerModelSetting;
use App\Models\Document;
use Illuminate\Support\Facades\Log;

/**
 * The read-only "test a question against THIS document" sandbox (Sprint 3,
 * spec C). It reuses the answer pipeline's hr-ai primitives — sandbox-retrieve
 * (single-document) → /synthesise → /ground — with the SAME per-call
 * answer-model key path as the employee loop, and PERSISTS NOTHING: no
 * chat_sessions / chat_messages / message_citations / message_traces /
 * escalation_cards are written.
 *
 * DELIBERATELY INDEPENDENT of {@see ChatService} (resolved Q4): the 2b answer
 * loop is frozen/durably-closed and carries the legal weight, so we accept a
 * small orchestration duplication here rather than refactor a shared helper out
 * of it. If sandbox-vs-real drift ever matters, a careful shared-helper refactor
 * is its own later change. This service makes no answer-loop change and creates
 * no escalation.
 */
class SandboxService
{
    /** Same conservative gates as the employee loop, read from named config. */
    private const SYNTHESIS_CHUNK_CAP = 10;

    public function __construct(
        private readonly ExtractionClient $ai,
        private readonly GroundingService $grounding,
    ) {}

    /**
     * Run one question scoped to a single document. Returns the answer/citations/
     * trace as a RESPONSE only — nothing is written anywhere.
     *
     * @return array<string,mixed>
     */
    public function run(Document $document, string $question): array
    {
        $retrievalFloor = (float) config('hr.retrieval_score_floor');
        $confidenceFloor = (float) config('hr.answer_confidence_floor');

        $trace = [
            'sandbox' => true,
            'document_uuid' => $document->uuid,
            'persisted' => false,
        ];

        // --- Retrieve this document's chunks (read-only, single-document) -------
        try {
            $resp = $this->ai->sandboxRetrieve($question, $document->id, self::SYNTHESIS_CHUNK_CAP);
        } catch (\Throwable $e) {
            Log::warning('sandbox: /sandbox-retrieve failed', ['detail' => $e->getMessage()]);

            return $this->result('No pude recuperar fragmentos de este documento.', [], $trace + [
                'outcome' => 'error', 'note' => 'sandbox-retrieve failed',
            ]);
        }

        $chunks = $resp['chunks'] ?? [];
        $topScore = empty($chunks) ? 0.0 : (float) collect($chunks)->max('score');
        $trace['retrieval'] = [
            'returned' => count($chunks),
            'top_score' => round($topScore, 6),
            'chunks' => collect($chunks)->map(fn ($c) => [
                'chunk_id' => $c['id'] ?? null,
                'page_from' => $c['page_from'] ?? null,
                'page_to' => $c['page_to'] ?? null,
                'score' => $c['score'] ?? null,
            ])->all(),
        ];

        // 0-chunk → honest "unanswerable from this document" (e.g. scanned doc).
        if (empty($chunks)) {
            return $this->result(
                'Este documento no tiene fragmentos indexados, así que no se puede responder a partir de él (p. ej. un PDF escaneado sin texto).',
                [], $trace + ['outcome' => 'no_chunks']
            );
        }

        // Check A — retrieval floor (same posture as the employee loop).
        if ($topScore < $retrievalFloor) {
            return $this->result(
                'La pregunta no encuentra apoyo suficiente en este documento (recuperación por debajo del umbral).',
                [], $trace + ['outcome' => 'escalate', 'escalation_reason' => 'low_confidence', 'note' => 'below retrieval floor']
            );
        }

        // --- Answer model must be configured to synthesise ----------------------
        $settings = AnswerModelSetting::current();
        $decryptedKey = $settings->isConfigured() ? $settings->decryptKey() : null;
        if ($decryptedKey === null) {
            return $this->result(
                'El modelo de respuesta no está configurado, así que el sandbox no puede sintetizar una respuesta.',
                [], $trace + ['outcome' => 'no_answer_model']
            );
        }

        $providerConfig = [
            'provider' => config('services.hr_ai.answer_provider', 'claude'),
            'model' => config('services.hr_ai.answer_model'),
            'endpoint' => config('services.hr_ai.answer_endpoint'),
        ];

        // Convenio chunks before national_law (authority precedence), score order within.
        $ordered = $this->orderByAuthority($chunks);
        $synthChunks = collect($ordered)->map(fn ($c) => [
            'chunk_id' => $c['id'],
            'document_id' => $c['document_id'],
            'page_from' => $c['page_from'] ?? null,
            'page_to' => $c['page_to'] ?? null,
            'content' => $c['content'],
            'score' => $c['score'] ?? 0.0,
            'authority_level' => $c['authority_level'] ?? null,
        ])->all();
        $providedChunkIds = collect($ordered)->pluck('id')->map(fn ($v) => (int) $v)->all();

        $synth = $this->ai->synthesise($question, $synthChunks, $decryptedKey, $providerConfig);
        if (isset($synth['error'])) {
            unset($decryptedKey);

            return $this->result(
                'El proveedor del modelo de respuesta falló; no se puede mostrar una respuesta en el sandbox.',
                [], $trace + ['outcome' => 'error', 'note' => 'provider error']
            );
        }

        $answer = trim((string) ($synth['answer'] ?? ''));
        $confidence = (float) ($synth['confidence'] ?? 0.0);
        $authorityUsed = $synth['authority_used'] ?? [];

        // Check B — citations present AND every cited chunk was provided.
        $validCitations = [];
        foreach (($synth['citations'] ?? []) as $cit) {
            $cid = isset($cit['chunk_id']) ? (int) $cit['chunk_id'] : null;
            if ($cid !== null && in_array($cid, $providedChunkIds, true)) {
                $validCitations[] = $cit;
            }
        }
        $checkB = count($validCitations) >= 1 && $answer !== '';

        // Per-claim entailment gate (same gate as the employee loop).
        $groundingResult = null;
        if ($checkB) {
            $byChunkId = collect($chunks)->keyBy(fn ($c) => (int) $c['id']);
            $citedChunks = [];
            foreach ($validCitations as $cit) {
                $chunk = $byChunkId->get((int) $cit['chunk_id']);
                if ($chunk && isset($chunk['content'])) {
                    $citedChunks[] = [
                        'chunk_id' => (int) $cit['chunk_id'],
                        'content' => (string) $chunk['content'],
                        'authority_level' => $chunk['authority_level'] ?? null,
                    ];
                }
            }
            $groundingResult = $this->grounding->check($question, $answer, $citedChunks, $decryptedKey, $providerConfig);
        }
        unset($decryptedKey);

        $pass = $checkB && ($groundingResult !== null && $groundingResult['grounded']);
        $trace['synthesis'] = [
            'provider' => $providerConfig['provider'],
            'model' => $providerConfig['model'],
            'citation_count' => count($validCitations),
            'confidence' => $confidence,
            'authority_used' => $authorityUsed,
        ];
        $trace['grounding'] = $groundingResult === null
            ? ['checked' => false, 'reason' => 'no valid citations']
            : ['checked' => true, 'grounded' => $groundingResult['grounded'], 'claims' => $groundingResult['claims'] ?? [], 'ungrounded' => $groundingResult['ungrounded'] ?? []];
        $trace['floor_decision'] = [
            'retrieval_score_floor' => $retrievalFloor,
            'answer_confidence_floor' => $confidenceFloor,
            'check_b_citations' => $checkB,
            'outcome' => $pass ? 'answer' : 'escalate',
            'escalation_reason' => $pass ? null : 'low_confidence',
            'authority_used' => $authorityUsed,
        ];

        // Surface the synthesized DRAFT + its citations in the trace either way —
        // so the admin can see what the model produced and (on escalate) exactly
        // which gate stopped it. This is purely informational; nothing persists.
        $resolved = $this->resolveCitations($validCitations, $chunks, $document);
        $trace['draft_answer'] = $answer;
        $trace['draft_citations'] = $resolved;

        if (! $pass) {
            return $this->result(
                'Con este documento, el sistema NO daría una respuesta (no supera la verificación de citas/fundamentación) y derivaría a una persona. Abajo se muestra el borrador que generó el modelo y por qué se detuvo.',
                [], $trace + ['outcome' => 'escalate']
            );
        }

        return $this->result($answer, $resolved, $trace + ['outcome' => 'answer']);
    }

    /**
     * @param  list<array<string,mixed>>  $citations
     * @param  array<string,mixed>  $trace
     * @return array<string,mixed>
     */
    private function result(string $answer, array $citations, array $trace): array
    {
        return [
            'answer' => $answer,
            'citations' => $citations,
            'trace' => $trace,
            'persisted' => false,
        ];
    }

    /**
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
     * @param  list<array<string,mixed>>  $validCitations
     * @param  list<array<string,mixed>>  $chunks
     * @return list<array<string,mixed>>
     */
    private function resolveCitations(array $validCitations, array $chunks, Document $document): array
    {
        $byChunkId = collect($chunks)->keyBy(fn ($c) => (int) $c['id']);
        $out = [];
        foreach ($validCitations as $cit) {
            $chunk = $byChunkId->get((int) $cit['chunk_id']);
            $content = (string) ($chunk['content'] ?? '');
            $out[] = [
                'chunk_id' => (int) $cit['chunk_id'],
                'document_uuid' => $document->uuid,
                'document_title' => $document->title,
                'page_from' => $cit['page_from'] ?? ($chunk['page_from'] ?? null),
                'page_to' => $cit['page_to'] ?? ($chunk['page_to'] ?? null),
                'snippet' => trim(mb_substr(preg_replace('/\s+/', ' ', $content) ?? '', 0, 160)),
            ];
        }

        return $out;
    }
}
