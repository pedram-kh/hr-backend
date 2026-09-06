<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Models\DocumentReviewTask;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Materialize the expiry review queue (Sprint 7a, §C / §7.6).
 *
 * The queue is primarily a QUERY over documents.validity_end + active prose
 * (data-model §10); this command turns the query into `document_review_tasks`
 * rows of type `expiry` so the queue persists and the successor-handoff is
 * tracked. Idempotent: one OPEN expiry task per qualifying document (no
 * duplicates on re-run).
 *
 * Window (§7.6): a document qualifies when it is `active` prose AND
 *   - validity_end is within EXPIRY_LEAD_DAYS (default 90) of today, OR
 *   - validity_end is already past while the document is still active (the
 *     Sprint-3 `date_expired_active` staleness signal — it needs the same human
 *     attention, so it feeds the same queue).
 *
 * This command NEVER changes retrieval_status and NEVER writes
 * predecessor_document_id — succession is a human-confirmed action
 * (DocumentController::succeed), never an automatic consequence.
 */
class ReviewsScanExpiry extends Command
{
    protected $signature = 'reviews:scan-expiry
                            {--days=90 : lead window before validity_end}
                            {--dry-run}
                            {--no-propose : skip the Sprint-7d AI successor suggestion}';

    protected $description = 'Materialize expiry review tasks for active prose nearing or past validity_end (a queue, never auto-retire).';

    // Prose authority types (salary tables are not prose; they are not chunked).
    private const PROSE_TYPES = ['convenio_text', 'national_law', 'partial_agreement', 'internal_hr_ruling'];

    public function handle(): int
    {
        $leadDays = (int) $this->option('days');
        $today = Carbon::today();
        $horizon = $today->copy()->addDays($leadDays);

        $documents = Document::query()
            ->with('documentType')
            ->whereHas('documentType', fn ($q) => $q->whereIn('code', self::PROSE_TYPES))
            ->where('retrieval_status', 'active')
            ->whereNotNull('validity_end')
            ->where('validity_end', '<=', $horizon) // within the lead window OR already past
            ->orderBy('validity_end')
            ->get();

        $created = 0;
        $existing = 0;
        foreach ($documents as $d) {
            $open = DocumentReviewTask::where('document_id', $d->id)
                ->where('type', 'expiry')
                ->where('status', 'open')
                ->first();

            if ($open !== null) {
                $existing++;
                $this->line("  [{$d->id}] {$d->title} — expiry task already open (due {$d->validity_end?->toDateString()})");

                continue;
            }

            $pastLabel = $d->validity_end->isPast() ? ' [ALREADY PAST — date_expired_active]' : '';
            $this->line("  [{$d->id}] {$d->title} — expires {$d->validity_end->toDateString()}{$pastLabel}");

            if (! $this->option('dry-run')) {
                $task = DocumentReviewTask::create([
                    'document_id' => $d->id,
                    'type' => 'expiry',
                    'reason' => null, // expiry tasks carry no unresolved/conflict reason
                    'raw_unmatched_values' => null,
                    'status' => 'open',
                    'due_date' => $d->validity_end->toDateString(),
                ]);
                $created++;

                // Sprint 7d (ADR-0024): ask for an AI successor SUGGESTION for the new
                // task. Queued so the scan stays fast, and INERT — the proposal writes
                // three columns on the task and nothing on any document, so this command
                // still "NEVER changes retrieval_status and NEVER writes
                // predecessor_document_id" exactly as documented above.
                if (! $this->option('no-propose')) {
                    \App\Jobs\ProposeSuccession::dispatch($task->id);
                }
            }
        }

        $this->newLine();
        $this->info("Expiry scan complete: {$created} new task(s), {$existing} already open, {$documents->count()} qualifying document(s).");

        return self::SUCCESS;
    }
}
