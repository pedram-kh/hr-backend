<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Calls hr-ai's internal endpoints (ADR-0010/0013):
 *  - /extract        PDF → per-page text + page-image keys (Sprint 1).
 *  - /extract-salary salary .xlsx → structured rows (extract-and-return; hr-ai
 *                    writes NO salary rows — hr-backend does).
 *  - /embed          re-extract column-aware → de-space → chunk → embed → hr-ai
 *                    WRITES document_chunks directly; hr-backend passes the
 *                    resolved scope (ADR-0007).
 *  - /synthesise     Sprint 2b-1 (ADR-0015): compose a CITED answer grounded only
 *                    in the eligible chunks. hr-backend passes the DECRYPTED key
 *                    in the body (never a header) per call; hr-ai never persists
 *                    it. hr-backend (not hr-ai) owns the answer-or-escalate
 *                    decision.
 * hr-backend (this app) remains the only writer of every table except
 * document_chunks.
 */
class ExtractionClient
{
    private function base(): string
    {
        return rtrim((string) config('services.hr_ai.url'), '/');
    }

    private function token(): string
    {
        return (string) config('services.hr_ai.internal_token');
    }

    /**
     * @return array{page_count:int, pages:list<array{page_number:int,text:string,image_key:string}>}
     */
    public function extract(string $storageKey, string $documentUuid): array
    {
        $response = Http::withHeaders(['X-Internal-Token' => $this->token()])
            ->timeout(180)
            ->acceptJson()
            ->post("{$this->base()}/extract", [
                'storage_key' => $storageKey,
                'document_uuid' => $documentUuid,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException("hr-ai /extract failed ({$response->status()}): ".$response->body());
        }

        return $response->json();
    }

    /**
     * Parse a salary .xlsx → { tables:[{sheet,year,validity_start,validity_end,
     * rows:[...]}], warnings:[...] }. hr-ai returns rows only; this app writes them.
     *
     * @return array<string,mixed>
     */
    public function extractSalary(string $storageKey, string $documentUuid): array
    {
        $response = Http::withHeaders(['X-Internal-Token' => $this->token()])
            ->timeout(180)
            ->acceptJson()
            ->post("{$this->base()}/extract-salary", [
                'storage_key' => $storageKey,
                'document_uuid' => $documentUuid,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException("hr-ai /extract-salary failed ({$response->status()}): ".$response->body());
        }

        return $response->json();
    }

    /**
     * Trigger chunk+embed for one document. hr-ai writes document_chunks directly
     * with the denormalized scope columns from $scope (resolved here, ADR-0007).
     *
     * @param  array<string,mixed>  $scope
     * @return array<string,mixed>
     */
    public function embed(int $documentId, string $documentUuid, string $storageKey, array $scope): array
    {
        $response = Http::withHeaders(['X-Internal-Token' => $this->token()])
            ->timeout(900) // model load + CPU embedding is a background admin path
            ->acceptJson()
            ->post("{$this->base()}/embed", [
                'document_id' => $documentId,
                'document_uuid' => $documentUuid,
                'storage_key' => $storageKey,
                'scope' => $scope,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException("hr-ai /embed failed ({$response->status()}): ".$response->body());
        }

        return $response->json();
    }

    /**
     * Vector retrieval primitive: scope-prefilter then exact similarity ranking
     * over document_chunks (data-model §11). Full recall (catch 2).
     *
     * @param  array<string,mixed>  $params
     * @return array<string,mixed>
     */
    public function retrieve(array $params): array
    {
        $response = Http::withHeaders(['X-Internal-Token' => $this->token()])
            ->timeout(180)
            ->acceptJson()
            ->post("{$this->base()}/retrieve", $params);

        if (! $response->successful()) {
            throw new RuntimeException("hr-ai /retrieve failed ({$response->status()}): ".$response->body());
        }

        return $response->json();
    }

    /**
     * Synthesise a cited answer from the eligible chunks (ADR-0015). The
     * decrypted answer-model key is passed in the BODY (never a header) and used
     * by hr-ai for this one call only — never persisted there.
     *
     * Returns either the synthesis envelope
     *   { answer, citations, grounding_signal, confidence, authority_used, trace_fragment }
     * or, on a provider failure, { error: 'provider_error', detail }. hr-backend
     * treats the latter as an escalation (low_confidence) — it never throws on a
     * provider failure, so the employee always gets an honest escalation.
     *
     * @param  list<array<string,mixed>>  $chunks
     * @param  array{provider:string,model:string,endpoint:?string}  $providerConfig
     * @return array<string,mixed>
     */
    public function synthesise(string $question, array $chunks, string $decryptedKey, array $providerConfig): array
    {
        $response = Http::withHeaders(['X-Internal-Token' => $this->token()])
            ->timeout(120)
            ->acceptJson()
            ->post("{$this->base()}/synthesise", [
                'question' => $question,
                'chunks' => $chunks,
                // Decrypted only in ChatService just before this call; passed in
                // the body, never logged, never bound beyond the call stack.
                'provider_api_key' => $decryptedKey,
                'provider_config' => $providerConfig,
            ]);

        if (! $response->successful()) {
            // A non-2xx is an hr-ai/transport failure (not a provider error, which
            // comes back as a 200 envelope). Surface as an escalatable signal.
            return ['error' => 'synthesis_unavailable', 'detail' => "hr-ai /synthesise failed ({$response->status()})"];
        }

        return $response->json();
    }
}
