<?php

namespace App\Services\Decline;

use App\Models\AnswerModelSetting;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Services\Agent\ToolResult;
use App\Services\Agent\TurnState;
use App\Services\GuardrailPolicy;
use App\Services\GuardrailService;
use App\Services\RouterService;
use Throwable;

/**
 * Slice 13e — gathers {@see DeclineFacts}. The one class here that reads the database, the router and the turn state; it
 * decides NOTHING (the gate does). The router confirmation (D8, plan.md §3.3) is spent only when every other check already
 * passes, so a vetoed / excluded turn never costs a call.
 */
final class DeclineFactsCollector
{
    public function __construct(
        private readonly GuardrailService $guardrails,
        private readonly GuardrailPolicy $policy,
        private readonly RouterService $router,
    ) {}

    public static function enabled(): bool
    {
        return (bool) config('hr.decline.enabled', true);
    }

    /** Planner source: the planner called `escalate{category, reason}`. */
    public function forPlanner(TurnState $state, string $category, string $plannerReason): DeclineFacts
    {
        $question = $state->question;
        $facts = new DeclineFacts(
            enabled: self::enabled(),
            source: DeclineFacts::SOURCE_PLANNER,
            reason: $category,
            baselineGuardHit: (bool) ($this->guardrails->check($question)['fired'] ?? false),
            adminSensitiveHit: $this->adminBlockedTopicHit($question),
            explicitRequest: $this->router->matchesExplicitRequest($question),
            acceptedNormalization: $state->normalizedTopicId() !== null || $state->normalizedCanonical() !== null,
            corpusMaterial: self::hasCorpusMaterial($state->material),
            pendingAsk: self::previousTurnWasAsk($state->session),
            vetoWord: DeclineVeto::match($question),
            guardRule: null,
            confirm: null,
            context: ['planner' => ['category' => $category, 'reason' => mb_substr($plannerReason, 0, 200)]],
        );

        // Spend the one router call only if it is the sole thing left to decide.
        if (DeclineGate::decide($facts)->failedIds() === ['D8']) {
            $facts = $facts->withConfirm($this->routerConfirm($question));
        }

        return $facts;
    }

    /**
     * Guard source: an enabled admin `off_domain` pattern matched (`PreModelGuards`, before any hr-ai call).
     *
     * @param  array{reason:string,rule:string,pattern:string}  $adminBlock
     */
    public function forGuardAdmin(string $question, array $adminBlock, ?ChatSession $session): DeclineFacts
    {
        return new DeclineFacts(
            enabled: self::enabled(),
            source: DeclineFacts::SOURCE_GUARD_ADMIN,
            reason: (string) $adminBlock['reason'],
            baselineGuardHit: (bool) ($this->guardrails->check($question)['fired'] ?? false),
            adminSensitiveHit: $this->adminBlockedTopicHit($question),
            explicitRequest: $this->router->matchesExplicitRequest($question),
            acceptedNormalization: false,
            corpusMaterial: false,
            pendingAsk: self::previousTurnWasAsk($session),
            vetoWord: null,
            guardRule: (string) $adminBlock['rule'],
            confirm: null,
            context: ['matched_pattern' => (string) $adminBlock['pattern']],
        );
    }

    /** D2 — ANY enabled admin `blocked_topic` row (not just the first match) counts. */
    private function adminBlockedTopicHit(string $question): bool
    {
        foreach ($this->policy->blockedTopicMatches($question) as $m) {
            if ($m['rule'] === 'admin_blocked_topic') {
                return true;
            }
        }

        return false;
    }

    /**
     * D5 — material that came from the corpus (or a tool that already decided). A `check_a_failed` miss is NOT material: it is
     * exactly what an off-domain question produces.
     *
     * @param  array<string,mixed>  $material
     */
    public static function hasCorpusMaterial(array $material): bool
    {
        foreach ($material as $result) {
            if (! $result instanceof ToolResult) {
                continue;
            }
            if ($result->status === ToolResult::MATERIAL || $result->status === ToolResult::TERMINAL) {
                return true;
            }
            if ($result->terminalOutcome !== null
                && ($result->terminalOutcome->trace['floor_decision']['check_a_retrieval'] ?? true) !== false) {
                return true; // entailment_failed / abstained: chunks DID clear Check A
            }
        }

        return false;
    }

    /** D6 — our own clarifying question is on the table; the reply to it is never declined. */
    public static function previousTurnWasAsk(?ChatSession $session): bool
    {
        if ($session === null || ! $session->exists) {
            return false;
        }
        $last = ChatMessage::query()
            ->where('session_id', $session->id)
            ->where('role', 'assistant')
            ->with('trace')
            ->orderByDesc('id')
            ->first();
        if ($last === null) {
            return false;
        }
        $outcome = $last->trace?->trace['floor_decision']['outcome'] ?? null;
        $kind = $last->trace?->trace['agent']['outcome'] ?? null;

        return in_array($outcome, ['ask', 'needs_category'], true) || in_array($kind, ['ask', 'needs_category'], true);
    }

    /**
     * D8 — one independent `/route` vote on the bare question. Any failure is a "no": the router's own fail-safe returns
     * label `prose`, which the gate denies.
     *
     * @return array{label:string,confidence:float,floor:float,source:?string}
     */
    private function routerConfirm(string $question): array
    {
        $floor = (float) config('hr.decline.router_confirm_floor', 0.90);
        try {
            $settings = AnswerModelSetting::current();
            $key = $settings->isConfigured() ? $settings->decryptKey() : null;
            $d = $this->router->classify($question, $key, [
                'provider' => config('services.hr_ai.answer_provider', 'claude'),
                'model' => config('services.hr_ai.router_model'),
                'endpoint' => config('services.hr_ai.router_endpoint'),
            ]);
            unset($key);

            return ['label' => (string) $d['label'], 'confidence' => (float) $d['confidence'], 'floor' => $floor, 'source' => $d['source'] ?? null];
        } catch (Throwable) {
            return ['label' => 'error', 'confidence' => 0.0, 'floor' => $floor, 'source' => 'exception'];
        }
    }
}
