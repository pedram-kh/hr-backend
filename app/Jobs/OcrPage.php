<?php

namespace App\Jobs;

use App\Models\Document;
use App\Models\DocumentPage;
use App\Services\OcrService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * OCR exactly one page (Sprint 7e, ADR-0026, review.md §2.1). One job per
 * page — never a whole-document loop in one job (see `OcrDocumentPages`).
 *
 * `$timeout = 150` overrides the worker's `queue:work --tries=3 --timeout=120`
 * default (`deploy.md`) for this job class specifically: a real OCR call is
 * measured at 9-90 s/page (review.md §1.3/§1.5), so the default 120 s ceiling
 * is close enough to the worst case that a slow page could be killed
 * mid-call and retried — paying for the same vision call twice. `$tries` is
 * left at the worker default (3): a genuine transport failure (hr-ai
 * unreachable) before the provider call is safe to retry; a provider-level
 * failure (bad transcription, parse error) never throws here at all — see
 * `OcrService::ocrOnePage()` and `ExtractionClient::ocrPage()`'s non-throwing
 * convention — so it is never retried by the queue, only by a future explicit
 * re-run (ingest re-run, backfill re-run).
 */
class OcrPage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 150;

    public function __construct(public int $documentId, public int $pageNumber) {}

    public function handle(OcrService $ocr): void
    {
        $page = DocumentPage::where('document_id', $this->documentId)
            ->where('page_number', $this->pageNumber)
            ->first();

        if ($page === null) {
            return; // page/document deleted since dispatch — nothing to do
        }

        $result = $ocr->ocrOnePage($page);

        Log::info('OcrPage job finished', [
            'document_id' => $this->documentId,
            'page_number' => $this->pageNumber,
            'status' => $result['status'],
        ]);

        // Sprint 7a re-trigger (review.md §2.1): the ORIGINAL ProposeDocumentTags
        // dispatch (fired by DocumentIngestor at ingest, unresolved-only) saw
        // empty page text for every OCR'd page — OCR had not run yet — so
        // TagProposalService's existing no_extractable_text guard made it a
        // no-op. Once the LAST ocr_pending page of this document is processed
        // (success OR error — either way this page is no longer "pending"),
        // re-check: if the document is still under_review, fire
        // ProposeDocumentTags again so the review queue actually gets a
        // facet proposal now that text exists. No new job class for this — the
        // same job already used by the admin "re-suggest" button and Step 3's
        // backfill.
        //
        // NOTE: a page that errored above is deliberately left `ocr_pending`
        // (OcrService's contract) — so it still counts as "pending" here and
        // this re-trigger correctly waits for it, rather than firing early on a
        // failed page.
        $stillPending = DocumentPage::where('document_id', $this->documentId)
            ->where('extraction_source', 'ocr_pending')
            ->exists();

        if (! $stillPending) {
            $document = Document::find($this->documentId);
            if ($document !== null && $document->tagging_status === 'under_review') {
                ProposeDocumentTags::dispatch($this->documentId);
            }
        }
    }
}
