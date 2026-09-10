<?php

namespace App\Services;

use App\Models\AnswerModelSetting;
use App\Models\EscalationCard;
use App\Support\EscalationExplainer;
use App\Support\EscalationExplanationGuard;
use Illuminate\Support\Facades\Log;

/**
 * The "Resumen IA" paragraph writer — Sprint 7g Item 1 (ADR-0029).
 *
 * DECIDE-AND-STATE (the sprint spec's own instruction): hr-backend has NEVER
 * called an LLM provider directly — architecture.md §2/ADR-0007 route every
 * provider call through hr-ai's HTTP endpoints via {@see ExtractionClient},
 * which passes the decrypted key + `provider_config` in the body per call
 * (the router's own envelope). This service follows that SAME convention,
 * using `ExtractionClient::explain()` — hr-ai's `/explain` endpoint — with the
 * cheap `ROUTER_MODEL` (Haiku) in `provider_config`, the same model knob
 * `RouterService` uses for `/route`.
 *
 * SPRINT 7G FAST-FOLLOW (this version): originally this called `/synthesise`
 * (hr-ai's existing citation-grounded endpoint), with the rendered facts
 * posing as a single "chunk". Found live on staging: the model habitually
 * appended a `[Fuente 1]`-style citation marker to EVERY sentence — an
 * ingrained habit from that prompt's citation contract, which has nothing to
 * do with this restatement task — and hr-backend's no-new-claims check
 * correctly rejected the draft every single time (3/3 live attempts across
 * two different escalation reasons), so the AI paragraph never survived in
 * practice. Fixed by moving to `/explain`, a small, DEDICATED hr-ai endpoint
 * with its own prompt that forbids citation markers and verbatim quoting
 * outright — see hr-ai's `EXPLAIN_SYSTEM_PROMPT`. {@see EscalationExplanationGuard}
 * is UNCHANGED: it was correct, and stays the safety net regardless of which
 * prompt fed it.
 *
 * `$factsText` handed to /explain is not a document passage; it is the
 * rendered structured facts (each on its own line, `campo: valor`), and the
 * instruction is fixed: restate these facts, in plain HR Spanish, add
 * nothing. Nothing here can add a claim the facts don't already carry — the
 * deterministic no-new-claims check ({@see EscalationExplanationGuard})
 * verifies that independently, regardless of whatever hr-ai returned.
 *
 * On ANY failure (no answer-model key configured, provider/transport error,
 * or a failed no-new-claims check) this returns `explanation_text = null` —
 * the card falls back to `EscalationExplainer::factsToSentences()` on
 * display, per the sprint spec ("on failure/error, show structured facts as
 * sentences instead"). Never throws; a failed paragraph never blocks a card.
 */
class EscalationExplanationService
{
    private const INSTRUCTION = 'Escribe UN PÁRRAFO breve (3-5 frases) en español claro, dirigido al '
        .'equipo de RR.HH. que va a atender una consulta de un/a empleado/a que se ha escalado. Explica '
        .'qué se preguntó, qué se encontró, por qué se escaló, y cuál es la acción recomendada.';

    public function __construct(private readonly ExtractionClient $ai) {}

    /**
     * Generate and persist the AI paragraph for one card, or leave it null.
     * Always returns quietly — never throws (this runs from a queued job).
     */
    public function generateFor(EscalationCard $card): void
    {
        $facts = $card->explanation_facts;
        if (! is_array($facts) || $facts === []) {
            // No deterministic facts to restate (shouldn't happen — persistTurn
            // always computes them at creation) — nothing to write from.
            return;
        }

        $settings = AnswerModelSetting::current();
        if (! $settings->isConfigured()) {
            Log::info('escalation explanation: no answer-model key configured, skipping AI paragraph', ['card_uuid' => $card->uuid]);

            return;
        }

        $decryptedKey = $settings->decryptKey();
        $providerConfig = [
            'provider' => config('services.hr_ai.answer_provider', 'claude'),
            // The cheap router model (Haiku), reusing the router's own config
            // knob — this is NOT a synthesis-quality answer, just a restatement.
            'model' => config('services.hr_ai.router_model'),
            'endpoint' => config('services.hr_ai.router_endpoint'),
        ];

        $factsBlock = $this->renderFacts($facts);
        $synth = $this->ai->explain(self::INSTRUCTION, $factsBlock, $decryptedKey, $providerConfig);
        unset($decryptedKey);

        $costUsd = (float) ($synth['trace_fragment']['cost_usd'] ?? 0.0);
        // Cost logged per card (sprint spec), regardless of outcome below.
        Log::info('escalation explanation: AI paragraph attempt', [
            'card_uuid' => $card->uuid,
            'reason' => $card->reason,
            'model' => $providerConfig['model'],
            'cost_usd' => $costUsd,
        ]);

        if (isset($synth['error'])) {
            Log::warning('escalation explanation: provider/transport failure, falling back to structured sentences', [
                'card_uuid' => $card->uuid,
                'error' => $synth['error'],
            ]);

            return;
        }

        $paragraph = trim((string) ($synth['answer'] ?? ''));

        if (! EscalationExplanationGuard::passes($facts, $paragraph)) {
            Log::warning('escalation explanation: no-new-claims check failed, falling back to structured sentences', [
                'card_uuid' => $card->uuid,
                'paragraph' => $paragraph,
            ]);

            return;
        }

        $card->forceFill(['explanation_text' => $paragraph])->save();
    }

    /** Render the facts as `campo: valor` lines — the one thing hr-ai's model is allowed to see for this call. */
    private function renderFacts(array $facts): string
    {
        $lines = [
            'Qué se preguntó: '.($facts['asked'] ?? ''),
            'Qué se encontró: '.($facts['found'] ?? ''),
            'Por qué se escaló: '.($facts['stopped_reason'] ?? ''),
            'Acción recomendada: '.($facts['fix_action'] ?? ''),
        ];

        return implode("\n", $lines);
    }
}
