<?php

namespace App\Services;

use App\Jobs\EmbedDocument;
use App\Models\Document;
use App\Support\EmbedEligibility;

/**
 * Slice 12c. Marks one document pending and queues EmbedDocument. The HTTP
 * request returns after the insert; hr-ai is not called here.
 */
class EmbedQueue
{
    public function __construct(private EmbedEligibility $eligibility) {}

    public function queueIfEligible(Document $document): bool
    {
        $document->refresh();
        $document->load(['documentType', 'pages']);

        if (! $this->eligibility->eligible($document)) {
            return false;
        }

        $document->update([
            'embed_state' => 'pending',
            'embed_error_code' => null,
            'embed_state_at' => now(),
        ]);

        EmbedDocument::dispatch($document->id);

        return true;
    }
}
