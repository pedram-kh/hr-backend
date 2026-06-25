<?php

namespace App\Jobs;

use App\Models\Document;
use App\Services\TagProposalService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * The auto-trigger for the LLM tagging tier (Sprint 7a, §7.2 — QUEUED, not
 * synchronous).
 *
 * Dispatched after ingest for an `unresolved` document so the review queue
 * self-populates without blocking the (batch) ingest. The LLM call is slow; a
 * failure here must NEVER fail or roll back ingest — the document is already
 * safely persisted `under_review` (the embedding gate keeps it unretrievable),
 * so a failed proposal just leaves it in the human queue untouched.
 *
 * The same job backs the manual "re-suggest" admin action.
 */
class ProposeDocumentTags implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public int $documentId) {}

    public function handle(TagProposalService $tagger): void
    {
        $document = Document::find($this->documentId);
        if ($document === null) {
            return;
        }

        // Defensive (invariant 1): only ever propose for a doc that is still
        // under_review. A verified/auto_proposed (retrievable) doc is never
        // re-tagged by the AI on the auto path — proposing is inert and must not
        // touch an already-answerable document.
        if ($document->tagging_status !== 'under_review') {
            return;
        }

        try {
            $summary = $tagger->propose($document);
            Log::info('tag proposal completed', ['document_id' => $document->id, 'summary' => $summary]);
        } catch (\Throwable $e) {
            // Never rethrow into ingest; the doc stays in the human queue.
            Log::warning('tag proposal job failed (doc left in human queue)', [
                'document_id' => $document->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
