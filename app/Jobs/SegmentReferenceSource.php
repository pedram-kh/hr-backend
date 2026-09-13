<?php

namespace App\Jobs;

use App\Models\Document;
use App\Services\ReferenceFactProposalService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * The auto-trigger for the reference-fact segmentation tier (Sprint 7b-2,
 * ADR-0022 — QUEUED, not synchronous). The 7a ProposeDocumentTags pattern,
 * reused for fact segmentation.
 *
 * Dispatched after a `reference_source` is ingested so the AI-proposed facts
 * self-populate the review queue without blocking ingest. The segmentation LLM
 * call is slow; a failure here must NEVER fail or roll back ingest — the source
 * is already safely persisted (its content stored as display pages, never
 * embedded), so a failed segmentation just leaves it unsegmented in the human
 * queue. Every proposed fact is inert (`ai_agent`/`needs_review`, not
 * answerable) until a human verifies.
 *
 * The same job backs the manual "re-segment" admin action.
 *
 * Sprint 10c (spec §2.4) — dispatch-time validity capture, not job-time. A
 * batch of queued jobs can sit for minutes; if a document's validity window is
 * edited while jobs are still queued, a job that RE-READS `$document` at
 * execute time would stamp facts with the post-edit window even though it was
 * queued before the edit — a fact whose applicability is unresolved at the
 * moment it was proposed, exactly the wrong-but-confident shape. The fix:
 * the validity window is captured by the CALLER at `dispatch()` time (both
 * production call sites — `DocumentIngestor` and the manual re-segment action
 * — read `$document->validity_start/end` right before dispatching) and rides
 * through the queue payload as plain strings (`SerializesModels` carries them
 * exactly like `$documentId`). `handle()` never reads validity off the
 * (possibly since-edited) `$document` row — only `ReferenceFactProposalService`
 * does, and only from these captured constructor properties, never from a
 * fresh model reload.
 */
class SegmentReferenceSource implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public int $documentId,
        public ?string $capturedValidityStart,
        public ?string $capturedValidityEnd,
    ) {}

    public function handle(ReferenceFactProposalService $segmenter): void
    {
        $document = Document::with('documentType')->find($this->documentId);
        if ($document === null) {
            return;
        }

        // Defensive (the routing invariant): segment ONLY a reference_source. The
        // type gates the path — never content. A non-reference doc is never
        // segmented into reference facts (a salary doc stays on the salary path).
        if ($document->documentType?->code !== 'reference_source') {
            return;
        }

        try {
            $summary = $segmenter->propose($document, $this->capturedValidityStart, $this->capturedValidityEnd);
            Log::info('reference segmentation completed', ['document_id' => $document->id, 'summary' => $summary]);
        } catch (\Throwable $e) {
            // Never rethrow into ingest; the source stays unsegmented.
            Log::warning('reference segmentation job failed (source left unsegmented)', [
                'document_id' => $document->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
