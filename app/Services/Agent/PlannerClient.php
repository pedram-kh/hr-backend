<?php

namespace App\Services\Agent;

/**
 * Sprint 13, build step 3 (skeleton) / step 6 (real transport) — the
 * planner's transport, next to `ExtractionClient` (ADR-0007/0015: hr-backend
 * decides and writes, hr-ai is stateless). Step 6 implements this against
 * hr-ai's `/plan` (native Anthropic tool use, `tool_choice: any`, thinking
 * disabled, no `temperature` param — §C.10). Tests bind a scripted fake,
 * exactly like `ExtractionClient`'s fakes in `Sprint7cAdditivityRegressionTest`.
 */
interface PlannerClient
{
    /**
     * @param  array<string,mixed>  $scopeSummary  §C.10 — what the planner is told about the employee
     * @param  array<string,mixed>  $window  §B.4.4 — the current session's recent turns
     * @param  list<array<string,mixed>>  $toolDefinitions  §C.8 — the enabled tools, in fixed order
     * @param  list<array<string,mixed>>  $priorSteps  this turn's own `trace.agent.steps` so far, for a multi-round conversation with the planner
     * @return array{stop_reason:string,calls:list<array{id:string,tool:string,input:array<string,mixed>}>,model:?string,request_id:?string,prompt_version:?string,tokens:array<string,mixed>,ms:int}
     *
     * @throws PlannerUnavailableException on a provider error, missing key, or unparseable response (§C.9 "Planner failure").
     */
    public function plan(string $question, array $scopeSummary, array $window, array $toolDefinitions, array $priorSteps): array;
}
