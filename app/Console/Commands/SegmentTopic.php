<?php

namespace App\Console\Commands;

use App\Jobs\SegmentConvenioTopic;
use App\Models\Document;
use App\Models\Topic;
use App\Services\ReferenceFactProposalService;
use Illuminate\Console\Command;

/**
 * Sprint 10c (plan §A.1/§D.11) — the per-(convenio, topic) driver's CLI entry
 * point. Runs INLINE by default (the `groups:propose` precedent — a CLI
 * backfill watches per-call cost/tokens directly), with `--queue` for the
 * real production batch run (step 5, gated on the topic's ⏸ checkpoint):
 * that path DISPATCHES `SegmentConvenioTopic` jobs (dispatch-time validity
 * capture, spec §2.4) instead of calling the service directly.
 *
 * `--dry-run` lists the eligible `(document, topic)` set with zero API
 * calls — D6's source-selection filter (`document_type=convenio_text` AND
 * `retrieval_status='active'`, full stop) made directly inspectable, no
 * hardcoded convenio skip list.
 *
 * No `--limit`/cap option exists here, deliberately (D3): the per-topic
 * checkpoint IS the ceiling — a human reviews each topic's gold-fixture eval
 * before its batch, not a mid-batch artificial cutoff.
 */
class SegmentTopic extends Command
{
    protected $signature = 'facts:segment-topic
                            {topic : approved topic name, e.g. "permisos"}
                            {--convenio=* : convenio id (repeatable); omit for every eligible convenio}
                            {--queue : dispatch SegmentConvenioTopic jobs instead of running inline (the real batch path)}
                            {--dry-run : list what would be segmented for, call nothing}';

    protected $description = 'Segment convenio text for ONE topic, per convenio (Sprint 10c) — inert needs_review facts, never approved.';

    public function handle(ReferenceFactProposalService $segmenter): int
    {
        $topicArg = mb_strtolower(trim((string) $this->argument('topic')));
        $topic = Topic::where('status', 'approved')
            ->whereRaw('lower(name) = ?', [$topicArg])
            ->first();
        if ($topic === null) {
            $this->error("No approved topic named '{$this->argument('topic')}'.");

            return self::FAILURE;
        }

        $documents = $segmenter->eligibleDocumentsForTopic($topic);

        $ids = array_map('intval', (array) $this->option('convenio'));
        if ($ids !== []) {
            $documents = $documents->filter(fn (Document $d) => in_array($d->convenio_id, $ids, true))->values();

            $missing = array_diff($ids, $documents->pluck('convenio_id')->all());
            if ($missing !== []) {
                $this->warn('No eligible document for convenio id(s) (no active convenio_text, or no anchored page for this topic): '.implode(', ', $missing));
            }
        }

        if ($documents->isEmpty()) {
            $this->error('No eligible documents for this topic (and convenio filter, if given).');

            return self::FAILURE;
        }

        $documents->loadMissing('convenio');

        if ($this->option('dry-run')) {
            $this->table(
                ['document_id', 'convenio_id', 'convenio', 'validity_start', 'validity_end'],
                $documents->map(fn (Document $d) => [
                    $d->id,
                    $d->convenio_id,
                    $d->convenio?->name,
                    $d->validity_start?->toDateString() ?? '(open)',
                    $d->validity_end?->toDateString() ?? '(open)',
                ])->all(),
            );
            $this->info("{$documents->count()} eligible document(s) for topic '{$topic->name}'.");

            return self::SUCCESS;
        }

        if ($this->option('queue')) {
            foreach ($documents as $document) {
                // Dispatch-time capture (spec §2.4) — read validity ONCE, here,
                // before queuing; SegmentConvenioTopic::handle() never re-reads it.
                SegmentConvenioTopic::dispatch(
                    $document->id,
                    $topic->id,
                    $document->validity_start?->toDateString(),
                    $document->validity_end?->toDateString(),
                );
            }
            $this->info("Dispatched {$documents->count()} SegmentConvenioTopic job(s) for topic '{$topic->name}'.");

            return self::SUCCESS;
        }

        $totalMs = 0;
        $rows = [];

        foreach ($documents as $document) {
            $this->line("→ doc #{$document->id} (convenio #{$document->convenio_id} {$document->convenio?->name})");

            // Same capture-at-call discipline as the --queue path, just with
            // no queue hop in between (an inline run has no dispatch/execute
            // gap to race).
            $capturedStart = $document->validity_start?->toDateString();
            $capturedEnd = $document->validity_end?->toDateString();

            $started = microtime(true);
            $summary = $segmenter->proposeForTopic($document, $topic, $capturedStart, $capturedEnd);
            $wallMs = (int) round((microtime(true) - $started) * 1000);
            $totalMs += $wallMs;

            if (($summary['status'] ?? '') !== 'ok') {
                $this->warn("   {$summary['status']}: ".($summary['reason'] ?? '?'));
                $rows[] = [$document->convenio_id, $document->convenio?->name, $summary['status'], '-', '-', '-', $wallMs.' ms'];

                continue;
            }

            $this->info(sprintf(
                '   %d fact(s): %d created, %d updated, %d dup-flagged · %d ms',
                $summary['facts'] ?? 0,
                $summary['created'] ?? 0,
                $summary['updated'] ?? 0,
                $summary['duplicates_flagged'] ?? 0,
                $wallMs,
            ));

            $rows[] = [
                $document->convenio_id,
                $document->convenio?->name,
                'ok',
                $summary['facts'] ?? 0,
                $summary['created'] ?? 0,
                $summary['updated'] ?? 0,
                $wallMs.' ms',
            ];
        }

        $this->newLine();
        $this->table(['convenio_id', 'convenio', 'status', 'facts', 'created', 'updated', 'time'], $rows);
        $this->info(sprintf("TOTAL: %d document(s) · topic '%s' · %d ms", $documents->count(), $topic->name, $totalMs));
        $this->comment('All facts are needs_review. Nothing is comparable by the answer path until a human verifies it. Cost: see the "topic segmentation: usage" log line per call (D1).');

        return self::SUCCESS;
    }
}
