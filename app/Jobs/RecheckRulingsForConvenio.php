<?php

namespace App\Jobs;

use App\Models\Document;
use App\Services\SemanticRecheckService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * The §8.5 reverse re-check trigger (Sprint 7d, ADR-0024) — QUEUED, flag-only.
 *
 * Dispatched when an `official_convenio` becomes active in a scope that already
 * holds published `internal_hr_ruling` documents: the new convenio may now speak
 * to a point a ruling was written to cover while the convenio was silent.
 *
 * Follows the 7a propose-job discipline exactly (`ProposeDocumentTags`): one try,
 * an int id in the constructor, a defensive type guard, and a `try/catch` that
 * logs and NEVER rethrows. A failure here must not fail the ingest or the
 * lifecycle edit that triggered it — the convenio is already safely persisted,
 * and a missed flag leaves today's documented boundary exactly as documented.
 */
class RecheckRulingsForConvenio implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public int $documentId) {}

    public function handle(SemanticRecheckService $recheck): void
    {
        $document = Document::find($this->documentId);
        if ($document === null) {
            return;
        }

        // Defensive: only an ACTIVE OFFICIAL CONVENIO triggers the reverse check.
        if ($document->authority_level !== 'official_convenio' || $document->retrieval_status !== 'active') {
            return;
        }

        try {
            $summary = $recheck->recheck($document);
            Log::info('semantic reverse re-check completed', $summary);
        } catch (\Throwable $e) {
            Log::warning('semantic reverse re-check failed (no flag raised; boundary unchanged)', [
                'document_id' => $document->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
