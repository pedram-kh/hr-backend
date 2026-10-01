<?php

namespace App\Services\Answer;

use App\Models\ChatSession;
use App\Services\ChatService;
use App\Services\Decline\DeclineFactsCollector;
use App\Services\Decline\DeclineGate;
use App\Services\Decline\DeclineGrant;
use App\Services\GuardrailPolicy;
use App\Services\GuardrailService;
use App\Services\RouterService;

/**
 * Sprint 13, build step 1 (plan.md §B.1) — `App\Services\Answer\PreModelGuards`,
 * extracted VERBATIM from `ChatService::handleMessage()` steps 2/2b/2c
 * (`ChatService.php:198-264`, pre-refactor line numbers): the guardrail
 * baseline, the admin guardrail layer, and the `explicit_request` pre-check —
 * every deterministic, no-hr-ai-call escalation gate that runs BEFORE the
 * reference-fact pre-check and the router.
 *
 * Returns a {@see TurnOutcome} the moment ANY of the three fires; `null` when
 * none does, so the caller falls through to the reference-fact pre-check /
 * router exactly as before. No condition, constant, or trace key was edited.
 */
class PreModelGuards
{
    public function __construct(
        private readonly GuardrailService $guardrail,
        private readonly GuardrailPolicy $policy,
        private readonly RouterService $router,
        private readonly DeclineFactsCollector $declines,
    ) {}

    /**
     * @param  array<string,mixed>  $trace
     * @param  ?ChatSession  $session  Slice 13e: only read by the decline gate's "previous turn was our own question" rule (D6)
     */
    public function check(string $question, array $trace, ?ChatSession $session = null): ?TurnOutcome
    {
        // --- Step 2: guardrail baseline (BEFORE the router and any hr-ai call) ---
        // The HARDCODED baseline runs first, unconditionally. It is the floor and
        // is never weakened by the admin layer.
        $guard = $this->guardrail->check($question);
        if ($guard['fired']) {
            $trace['guardrail_check'] = $guard;
            $trace['floor_decision'] = [
                'retrieval_score_floor' => $this->policy->retrievalFloor(),
                'answer_confidence_floor' => $this->policy->confidenceFloor(),
                'outcome' => 'escalate',
                'escalation_reason' => $guard['reason'],
            ];

            return new TurnOutcome('escalate', ChatService::ESCALATION_MESSAGE, [], $trace, $guard['reason']);
        }

        // --- Step 2b: admin guardrail layer (Sprint 6, ADR-0019) — a pure UNION on
        // top of the baseline, applied at the SAME pre-router point so an
        // admin-blocked question ALSO never reaches hr-ai (the privacy guarantee).
        // The baseline already returned above; this can only ADD escalations,
        // never suppress one. blocked_topic → sensitive_topic; off_domain → the
        // narrow-only boundary (with the admin refusal copy if configured).
        $adminBlock = $this->policy->blockedTopicMatch($question);
        if ($adminBlock !== null) {
            $trace['guardrail_check'] = ['fired' => true, 'reason' => $adminBlock['reason'], 'rule' => $adminBlock['rule'], 'layer' => 'admin', 'matched_pattern' => $adminBlock['pattern']];
            $trace['floor_decision'] = [
                'retrieval_score_floor' => $this->policy->retrievalFloor(),
                'answer_confidence_floor' => $this->policy->confidenceFloor(),
                'outcome' => 'escalate',
                'escalation_reason' => $adminBlock['reason'],
                'note' => 'admin guardrail layer ('.$adminBlock['rule'].') — additive, raise-only',
            ];
            // Slice 13e (ADR-0039): the admin's OWN off-domain pattern is a human decision, so it can be declined (no card) —
            // but only if the gate grants it. A denial (or the kill switch) leaves the escalation above exactly as it was; a
            // denial adds the `decline` block so HR can see which check refused.
            if ($adminBlock['reason'] === 'off_domain' && DeclineFactsCollector::enabled()) {
                $decision = DeclineGate::decide($this->declines->forGuardAdmin($question, $adminBlock, $session));
                $trace['decline'] = $decision->toTrace();
                if ($decision->granted) {
                    $trace['floor_decision'] = [
                        'path' => 'pre_model_guard',
                        'retrieval_score_floor' => $this->policy->retrievalFloor(),
                        'answer_confidence_floor' => $this->policy->confidenceFloor(),
                        'outcome' => 'decline',
                        'decline_reason' => DeclineGate::ONLY_REASON,
                        'note' => 'admin guardrail layer ('.$adminBlock['rule'].') — declined, no escalation card',
                    ];

                    return TurnOutcome::decline(DeclineGrant::fromDecision($decision), ChatService::DECLINE_MESSAGE, $trace);
                }
            }

            $message = $adminBlock['reason'] === 'off_domain'
                ? ($this->policy->offDomainMessage() ?? ChatService::ESCALATION_MESSAGE)
                : ChatService::ESCALATION_MESSAGE;

            return new TurnOutcome('escalate', $message, [], $trace, $adminBlock['reason']);
        }

        // --- Step 2c: explicit_request pre-check (Sprint 10b, ADR-0033) --------
        // Deterministic, NO LLM — same calling convention as the salary pre-
        // classifier (RouterService::matchesSalary(), called directly below at
        // Step 2d). Runs AFTER both guardrail layers above (build authorization
        // D3: a message that is simultaneously sensitive AND a human request
        // must escalate sensitive_topic — the stronger, already-cleared reason —
        // never this weaker, contentless one) and BEFORE the reference-fact
        // pre-check and the router (a bare human-request phrase has no topic to
        // anchor on and nothing to classify; checking it first on a narrow closed
        // list is safer and cheaper than letting it reach the LLM router, where
        // today it is misclassified off_domain — plan.md §0/§C.4, card 9).
        //
        // `explicit_request` has existed in the reason enum and the 7g
        // EscalationExplainer matrix/registry since Sprint 4/7g (reserved, "not
        // currently emitted" — EscalationExplainer.php:44); this is its first
        // producer. No migration, no explainer change, no guard-test change
        // needed (plan.md §C.4 confirmed all three already cover it).
        if ($this->router->matchesExplicitRequest($question)) {
            $trace['floor_decision'] = [
                'retrieval_score_floor' => $this->policy->retrievalFloor(),
                'answer_confidence_floor' => $this->policy->confidenceFloor(),
                'outcome' => 'escalate',
                'escalation_reason' => 'explicit_request',
                'note' => 'employee explicitly asked to speak with a person (deterministic pre-check, before router)',
            ];

            return new TurnOutcome('escalate', ChatService::ESCALATION_MESSAGE, [], $trace, 'explicit_request');
        }

        return null;
    }
}
