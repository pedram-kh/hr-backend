<?php

namespace App\Jobs;

use App\Models\Document;
use App\Models\Topic;
use App\Services\ReferenceFactProposalService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Sprint 10c (plan §A.1/§D.11 step 3) — the per-(document, topic) batch driver
 * job, alongside (NOT replacing) {@see SegmentReferenceSource}. Segments a
 * `convenio_text` document's topic-anchored passages for exactly ONE topic,
 * rather than an uploaded `reference_source` recopilación against the full
 * closed topic list.
 *
 * Deliberately NOT auto-dispatched on ingest (unlike SegmentReferenceSource) —
 * dispatched only by the batch command (`facts:segment-topic`), one job per
 * eligible `(document, topic)` pair, per D3's "per-topic checkpoint is the
 * ceiling" decision (no per-batch job cap; the human checkpoint gates the next
 * topic, not the queue).
 *
 * Carries the exact same dispatch-time validity-capture discipline as
 * `SegmentReferenceSource` (spec §2.4) — the batch command reads
 * `$document->validity_start/end` ONCE, at dispatch, before queuing hundreds
 * of jobs; `handle()` never re-reads validity off `$document` here.
 */
class SegmentConvenioTopic implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public int $documentId,
        public int $topicId,
        public ?string $capturedValidityStart,
        public ?string $capturedValidityEnd,
    ) {}

    public function handle(ReferenceFactProposalService $segmenter): void
    {
        $document = Document::with('documentType')->find($this->documentId);
        $topic = Topic::find($this->topicId);
        if ($document === null || $topic === null) {
            return;
        }

        // Defensive (mirrors SegmentReferenceSource's routing invariant): this
        // driver only ever segments already-ingested convenio text, never a
        // reference_source upload (that stays the other job's path).
        if ($document->documentType?->code !== 'convenio_text') {
            return;
        }

        try {
            $summary = $segmenter->proposeForTopic($document, $topic, $this->capturedValidityStart, $this->capturedValidityEnd);
            Log::info('topic segmentation completed', [
                'document_id' => $document->id,
                'topic_id' => $topic->id,
                'topic_name' => $topic->name,
                'summary' => $summary,
            ]);
        } catch (\Throwable $e) {
            // Never let one convenio's failure interrupt the batch; it just
            // stays unsegmented for this topic.
            Log::warning('topic segmentation job failed (document left unsegmented for this topic)', [
                'document_id' => $document->id,
                'topic_id' => $topic->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
