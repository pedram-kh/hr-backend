<?php

namespace App\Services;

use App\Models\Document;

/**
 * The single-document body of `chunks:embed --document=<uuid>` (Slice 12c).
 *
 * Resolves scope and calls hr-ai /embed for this document only. It does not
 * build the global selection; `ChunksEmbed` still owns that query and calls
 * this method once per selected row. Refuses `under_review` even if a caller
 * hands the row in.
 */
class DocumentEmbedder
{
    public function __construct(private ExtractionClient $client) {}

    /**
     * @return array<string,mixed>|null null when the document is under review
     */
    public function embed(Document $document): ?array
    {
        $document->loadMissing(['documentType', 'convenio']);

        if ($document->tagging_status === 'under_review') {
            return null;
        }

        return $this->client->embed(
            $document->id,
            $document->uuid,
            (string) $document->storage_path,
            $this->resolveScope($document),
        );
    }

    /**
     * Denormalized scope for document_chunks (ADR-0007). Territory and sector
     * ride the convenio. Moved verbatim from ChunksEmbed::resolveScope.
     *
     * @return array<string,mixed>
     */
    public function resolveScope(Document $document): array
    {
        return [
            'convenio_id' => $document->convenio_id,
            'territory_id' => $document->convenio?->territory_id,
            'sector_id' => $document->convenio?->sector_id,
            'validity_start' => $document->validity_start?->toDateString(),
            'validity_end' => $document->validity_end?->toDateString(),
            'retrieval_status' => $document->retrieval_status,
            'authority_level' => $document->authority_level,
        ];
    }
}
