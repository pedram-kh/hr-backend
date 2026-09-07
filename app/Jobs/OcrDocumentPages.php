<?php

namespace App\Jobs;

use App\Models\DocumentPage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * The ingest-time OCR fan-out (Sprint 7e, ADR-0026, review.md §2.1). Dispatched
 * by `DocumentIngestor::ingest()` after the ingest transaction commits, exactly
 * once per document, whenever at least one page came back `ocr_pending` from
 * hr-ai's `/extract`.
 *
 * Does NO OCR itself — it only enqueues ONE `OcrPage` job per pending page and
 * returns immediately. This is deliberate: at up to `ocr_page_cap` (default 60)
 * pages × up to ~90 s each (review.md §1.3/§1.5), a single job doing all of them
 * in a loop could run past 90 minutes — wildly over the worker's 120 s default
 * timeout and not something a `$timeout` override should paper over for a job
 * that fans out naturally. One job per page is the queue-native shape: retries,
 * failures, and progress are all per-page, never all-or-nothing per document.
 */
class OcrDocumentPages implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public int $documentId) {}

    public function handle(): void
    {
        $pageNumbers = DocumentPage::where('document_id', $this->documentId)
            ->where('extraction_source', 'ocr_pending')
            ->orderBy('page_number')
            ->pluck('page_number');

        foreach ($pageNumbers as $pageNumber) {
            OcrPage::dispatch($this->documentId, $pageNumber);
        }
    }
}
