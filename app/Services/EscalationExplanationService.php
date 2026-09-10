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
 * (the router's own envelope). This service follows that SAME convention: it
 * reuses `ExtractionClient::synthesise()` — hr-ai's EXISTING `/synthesise`
 * endpoint, no new hr-ai endpoint, no new hr-ai code at all — with the cheap
 * `ROUTER_MODEL` (Haiku) in `provider_config`, exactly the way `RouterService`
 * already does for `/route`. hr-ai remains unchanged.
 *
 * The "chunk" handed to /synthesise is not a document passage; it is the
 * rendered structured facts (each on its own line, `campo: valor`), and the
 * "question" is a fixed instruction: restate these facts, in plain HR
 * Spanish, add nothing. Nothing here can add a claim the facts don't already
 * carry — hr-ai's /synthesise is a plain "compose text grounded only in the
 * provided content" call, and the deterministic no-new-claims check
 * ({@see EscalationExplanationGuard}) verifies that afterward, independent of
 * whatever hr-ai returned.
 *
 * On ANY failure (no answer-model key configured, provider/transport error,
 * or a failed no-new-claims check) this returns `explanation_text = null` —
 * the card falls back to `EscalationExplainer::factsToSentences()` on
 * display, per the sprint spec ("on failure/error, show structured facts as
 * sentences instead"). Never throws; a failed paragraph never blocks a card.
 */
class EscalationExplanationService
{
    /** A synthetic chunk id — never resolved against real document_chunks; this call has no citation to persist. */
    private const FACTS_CHUNK_ID = 1;

    private const INSTRUCTION = 'Basándote ÚNICAMENTE en los siguientes hechos estructurados sobre una '
        .'consulta de un/a empleado/a que se ha escalado a Recursos Humanos, escribe UN PÁRRAFO breve '
        .'(3-5 frases) en español claro, dirigido al equipo de RR.HH. que va a atenderla. Explica qué se '
        .'preguntó, qué se encontró, por qué se escaló, y cuál es la acción recomendada. No añadas ningún '
        .'dato, cifra, nombre o hecho que no esté explícitamente en la lista. No inventes nada. No uses '
        .'markdown ni listas — un párrafo de prosa normal.';

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
        $synth = $this->ai->synthesise(self::INSTRUCTION, [[
            'chunk_id' => self::FACTS_CHUNK_ID,
            'document_id' => 0,
            'page_from' => null,
            'page_to' => null,
            'content' => $factsBlock,
            'score' => 1.0,
            'authority_level' => 'structured_reference',
        ]], $decryptedKey, $providerConfig);
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
