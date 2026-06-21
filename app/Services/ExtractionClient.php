<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Calls hr-ai's `/extract` endpoint (ADR-0010). hr-ai reads the original from
 * S3, writes page images to S3, and returns per-page text + image keys.
 * hr-backend (this app) is the only writer of documents/document_pages.
 */
class ExtractionClient
{
    /**
     * @return array{page_count:int, pages:list<array{page_number:int,text:string,image_key:string}>}
     */
    public function extract(string $storageKey, string $documentUuid): array
    {
        $base = rtrim((string) config('services.hr_ai.url'), '/');
        $token = (string) config('services.hr_ai.internal_token');

        $response = Http::withHeaders(['X-Internal-Token' => $token])
            ->timeout(180)
            ->acceptJson()
            ->post("{$base}/extract", [
                'storage_key' => $storageKey,
                'document_uuid' => $documentUuid,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException("hr-ai /extract failed ({$response->status()}): ".$response->body());
        }

        return $response->json();
    }
}
