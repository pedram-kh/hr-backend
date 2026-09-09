<?php

namespace App\Jobs;

use App\Models\Convenio;
use App\Services\ConvenioGroupProposalService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * The group-structure proposer's trigger (Sprint 7f, ADR-0028) — the 7a
 * `ProposeDocumentTags` / 7b-2 `SegmentReferenceSource` pattern, reused for
 * structure.
 *
 * QUEUED, and deliberately NOT dispatched on ingest. Unlike fact segmentation,
 * this is a per-CONVENIO read of every document bound to that convenio, so
 * firing it per uploaded file would re-read the whole convenio once per upload
 * for no new information. It is invoked deliberately instead: `groups:propose`
 * for a backfill, or the admin "propose structure" action on the Groups tab.
 *
 * A failure here must never surface as anything but "this convenio has no
 * proposed structure yet". That is a safe state by construction: Phase 3's
 * matcher joins `status = 'approved'` only, so an absent proposal leaves the
 * existing behaviour exactly as it is rather than degrading it.
 */
class ProposeConvenioGroups implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public int $convenioId) {}

    public function handle(ConvenioGroupProposalService $proposer): void
    {
        $convenio = Convenio::find($this->convenioId);
        if ($convenio === null) {
            return;
        }

        try {
            $summary = $proposer->propose($convenio);
            Log::info('convenio group proposal completed', [
                'convenio_id' => $convenio->id,
                'summary' => $summary,
            ]);
        } catch (\Throwable $e) {
            // Never rethrow: the convenio simply stays without a proposed
            // structure, which changes nothing about how it answers today.
            Log::warning('convenio group proposal failed (convenio left without a structure)', [
                'convenio_id' => $convenio->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
