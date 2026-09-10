<?php

namespace App\Console\Commands;

use App\Jobs\GenerateEscalationExplanationText;
use App\Models\EscalationCard;
use App\Models\MessageTrace;
use App\Support\EscalationExplainer;
use Illuminate\Console\Command;

/**
 * Sprint 7g Item 1 (ADR-0029) — backfill `explanation_facts`/`fix_*` on every
 * pre-7g card (every card created before `ChatService::persistTurn()` started
 * computing them). The AI paragraph is left to the normal queued job
 * (dispatched per card here) rather than run synchronously in this command —
 * a CLI loop calling the provider N times in a row is exactly the "held
 * transaction / no retry" shape the queue exists to avoid.
 *
 * The deterministic facts need the ORIGINAL turn's trace (reason + trace ->
 * facts), read from `message_traces` via the card's `source_message_id`'s
 * SESSION — the trace is on the ASSISTANT message, one after the user message
 * that escalated. A card whose session/trace can no longer be resolved (should
 * not happen; defensive) is skipped and reported, never guessed.
 */
class EscalationsBackfillExplanations extends Command
{
    protected $signature = 'escalations:backfill-explanations
        {--dry-run : report the selection only, write nothing}
        {--skip-ai : compute the deterministic facts only, do not queue the AI paragraph job}';

    protected $description = 'Backfill explanation_facts/fix_* (and queue the AI paragraph) on every escalation card created before Sprint 7g Item 1.';

    public function handle(): int
    {
        $cards = EscalationCard::whereNull('explanation_facts')->orderBy('id')->get();
        $this->info("Cards missing an explanation: {$cards->count()}");

        if ($this->option('dry-run')) {
            foreach ($cards as $c) {
                $this->line("  [{$c->id}] {$c->uuid} · reason={$c->reason}");
            }

            return self::SUCCESS;
        }

        $done = 0;
        $skipped = 0;

        foreach ($cards as $card) {
            $trace = $this->resolveTrace($card);
            if ($trace === null) {
                $this->warn("  [{$card->id}] {$card->uuid} — could not resolve the originating trace, skipped");
                $skipped++;

                continue;
            }

            $explanation = EscalationExplainer::explain($card->reason, $trace);
            $card->forceFill([
                'explanation_facts' => $explanation,
                'fix_action' => $explanation['fix_action'],
                'fix_surface' => $explanation['fix_surface'],
                'fix_link' => $explanation['fix_link'],
            ])->save();

            if (! $this->option('skip-ai')) {
                GenerateEscalationExplanationText::dispatch($card->uuid);
            }

            $done++;
        }

        $this->info("Backfilled {$done} card(s); skipped {$skipped}.");

        return self::SUCCESS;
    }

    /**
     * The trace on the assistant message that FOLLOWS the card's
     * `source_message_id` (the user turn) within the same session — the exact
     * turn that escalated. Returns null if the session/messages/trace can't
     * be resolved (defensive; every real card has one).
     *
     * @return array<string,mixed>|null
     */
    private function resolveTrace(EscalationCard $card): ?array
    {
        if ($card->source_message_id === null) {
            return null;
        }

        $assistantMessage = $card->session?->messages()
            ->where('role', 'assistant')
            ->where('id', '>', $card->source_message_id)
            ->orderBy('id')
            ->first();

        if ($assistantMessage === null) {
            return null;
        }

        $trace = MessageTrace::where('message_id', $assistantMessage->id)->first();

        return $trace?->trace;
    }
}
