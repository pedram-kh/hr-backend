<?php

namespace App\Jobs;

use App\Models\EscalationCard;
use App\Services\EscalationExplanationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Sprint 7g Item 1 (ADR-0029) — the "Resumen IA" paragraph, generated
 * ASYNCHRONOUSLY (queued, dispatched `->afterCommit()` from `ChatService::
 * persistTurn()`). The deterministic facts are already on the card by the
 * time this runs (computed synchronously at creation); this job only adds
 * `explanation_text` on top, or leaves it null on any failure — mirrors
 * `ProposeDocumentTags`: a failure here never blocks or retries loudly, the
 * card is already fully usable (facts + fix link) without it.
 */
class GenerateEscalationExplanationText implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public string $cardUuid) {}

    public function handle(EscalationExplanationService $service): void
    {
        $card = EscalationCard::where('uuid', $this->cardUuid)->first();
        if ($card === null) {
            return;
        }

        try {
            $service->generateFor($card);
        } catch (\Throwable $e) {
            Log::warning('escalation explanation job failed (card keeps its structured facts, no AI paragraph)', [
                'card_uuid' => $this->cardUuid,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
