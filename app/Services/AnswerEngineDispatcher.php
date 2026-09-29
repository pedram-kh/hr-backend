<?php

namespace App\Services;

use App\Models\AnswerEngineSetting;
use App\Models\Employee;
use App\Services\Agent\AgentChatService;

/**
 * Sprint 13, build step 2 (plan.md §E.15 step 2, §F.14) — the ONE seam
 * `ChatController::message()` calls instead of `ChatService::handleMessage()`
 * directly. Resolves which engine serves a turn (env baseline `classic`,
 * overridable at runtime via the `answer_engine_settings` single row, or an
 * explicit `$engine` argument for `php artisan answer:gate` — step 7 — to run
 * both engines side by side without touching the global switch) and
 * delegates.
 *
 * Step 3 wires the `agent` branch to `App\Services\Agent\AgentChatService`
 * (step 2 had it delegate to classic verbatim, since the shell didn't exist
 * yet). This is safe before the planner exists (step 6): every turn round 0
 * cannot resolve deterministically falls back to classic wholesale via
 * `PlannerUnavailableException` (§F.10) — see `AgentChatService`'s own
 * docblock.
 */
class AnswerEngineDispatcher
{
    public function __construct(
        private readonly ChatService $classic,
        private readonly AgentChatService $agent,
    ) {}

    /**
     * The engine that would serve a turn right now, absent an explicit
     * override: the DB single-row override if set, else the
     * `HR_ANSWER_ENGINE` env baseline (default `classic`).
     */
    public function effectiveEngine(): string
    {
        $override = AnswerEngineSetting::current()->engine;
        if ($override !== null && in_array($override, AnswerEngineSetting::VALID_ENGINES, true)) {
            return $override;
        }

        $baseline = config('hr.answer_engine', AnswerEngineSetting::CLASSIC);

        return in_array($baseline, AnswerEngineSetting::VALID_ENGINES, true) ? $baseline : AnswerEngineSetting::CLASSIC;
    }

    /**
     * Handle one employee turn end to end, per `ChatService::handleMessage()`'s
     * own contract (same params, same return shape — the response payload).
     *
     * @param  string|null  $engine  Explicit override (`classic`|`agent`), bypassing
     *                               the DB/env resolution — used by `answer:gate`
     *                               (step 7) to run both engines on the same
     *                               fixture without flipping the global switch.
     *                               Defaults to {@see effectiveEngine()}.
     * @return array<string,mixed>
     */
    public function handle(
        Employee $employee,
        string $question,
        ?string $sessionUuid = null,
        ?int $selectedJobCategoryId = null,
        ?string $engine = null,
    ): array {
        $engine = $engine ?? $this->effectiveEngine();

        return match ($engine) {
            AnswerEngineSetting::AGENT => $this->agent->handle($employee, $question, $sessionUuid, $selectedJobCategoryId),
            default => $this->classic->handleMessage($employee, $question, $sessionUuid, $selectedJobCategoryId),
        };
    }
}
