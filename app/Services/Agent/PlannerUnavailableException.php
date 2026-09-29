<?php

namespace App\Services\Agent;

use RuntimeException;

/**
 * Sprint 13, build step 3 (plan.md §C.9 "Planner failure", §F.10) — thrown by
 * a {@see PlannerClient} on a provider error, missing key, or an unparseable
 * round-1 response. {@see AgentChatService} catches this and falls back to
 * running `classic` wholesale for that turn, recording
 * `trace.agent.termination = 'planner_unavailable_classic_fallback'`
 * (the plan's own recommendation, §F.10) — never a partial, half-agent answer.
 */
class PlannerUnavailableException extends RuntimeException {}
