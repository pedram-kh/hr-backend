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
}
