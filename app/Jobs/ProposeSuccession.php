<?php

namespace App\Jobs;

use App\Models\DocumentReviewTask;
use App\Services\SuccessionProposalService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Sprint 7d (ADR-0024), part C — the queued succession proposer.
 *
 * Dispatched by `reviews:scan-expiry` for each newly-created expiry task, and by
 * the manual re-run endpoint. Follows the 7a propose-job discipline exactly
 * (`ProposeDocumentTags`): one try, an int id in the constructor, a defensive
 * guard, and a `try/catch` that logs and NEVER rethrows — the expiry queue is
 * already populated and useful without a proposal, so a comparison failure must
 * not fail the scan.
 *
 * The proposal it writes is INERT: three columns on the task, nothing on the
 * document. See SuccessionProposalService.
 */
class ProposeSuccession implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public int $taskId) {}

    public function handle(SuccessionProposalService $proposer): void
    {
        $task = DocumentReviewTask::with('document')->find($this->taskId);
        if ($task === null) {
            return;
        }

        // Defensive: only an OPEN EXPIRY task gets a succession proposal. A resolved
        // task must never be re-decorated (the human is done with it), and a
        // conflict/tag_review task is a different question entirely.
        if ($task->type !== 'expiry' || $task->status !== 'open') {
            return;
        }

        try {
            $summary = $proposer->propose($task);
            Log::info('succession proposal completed', $summary);
        } catch (\Throwable $e) {
            Log::warning('succession proposal job failed (expiry task left un-decorated)', [
                'task_id' => $task->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
