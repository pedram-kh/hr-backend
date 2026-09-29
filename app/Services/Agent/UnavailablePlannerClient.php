<?php

namespace App\Services\Agent;

/**
 * Sprint 13, build step 3 — the default `PlannerClient` binding until step 6
 * ships the real hr-ai `/plan` transport. Always throws, so any turn that
 * round 0 cannot resolve deterministically falls back to classic (§F.10) —
 * this makes it SAFE to wire {@see AgentChatService} into the
 * `AnswerEngineDispatcher` before the planner exists: the only turns the
 * agent engine can actually diverge from classic on, before step 6, are
 * turns round 0 already resolves identically to classic by construction.
 */
class UnavailablePlannerClient implements PlannerClient
{
    public function plan(string $question, array $scopeSummary, array $window, array $toolDefinitions, array $priorSteps): array
    {
        throw new PlannerUnavailableException('no PlannerClient is bound yet (step 6 ships the real hr-ai /plan transport)');
    }
}
