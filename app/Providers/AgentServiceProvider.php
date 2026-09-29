<?php

namespace App\Providers;

use App\Services\Agent\RuleEngine;
use App\Services\Agent\Rules\AskEmployeePostCallRule;
use App\Services\Agent\Rules\AskEmployeeWhitelist;
use App\Services\Agent\Rules\ClarificationBudgetRule;
use App\Services\Agent\Rules\FigureNotFromTablePostCallRule;
use App\Services\Agent\Rules\GeneralLaneAvailabilityRule;
use App\Services\Agent\Rules\GeneralLaneFinishRule;
use App\Services\Agent\Rules\GeneralLanePostCheck;
use App\Services\Agent\Rules\NationalLawPrecedenceRule;
use App\Services\Agent\Rules\PeriodSupportGuard;
use App\Services\Agent\Rules\ProseCheckAPostCallRule;
use App\Services\Agent\Rules\ReferenceFactPostCallRule;
use App\Services\Agent\Rules\ReferenceFactSalaryPrecedenceRule;
use App\Services\Agent\Rules\SalaryIntentPreCallRule;
use App\Services\Agent\Rules\SalaryLookupPostCallRule;
use App\Services\Agent\ToolRegistry;
use App\Services\Agent\Tools\AskEmployeeTool;
use App\Services\Agent\Tools\ConvenioSearchTool;
use App\Services\Agent\Tools\GeneralKnowledgeTool;
use App\Services\Agent\Tools\NationalLawTool;
use App\Services\Agent\Tools\ReferenceFactTool;
use App\Services\Agent\Tools\SalaryLookupTool;
use App\Services\GuardrailPolicy;
use Illuminate\Support\ServiceProvider;

/**
 * Sprint 13, build step 3 (plan.md §D.11) — wires the agent engine's shared,
 * per-request `RuleEngine` (with the built-in, tool-agnostic budget rules
 * registered once) and `ToolRegistry` (real v1 tools register themselves
 * here from step 5 onward, in FIXED spec order — §C.10's determinism
 * requirement). Singletons: both must be the SAME instance across every
 * `Tool`/`Rule` resolved for one request, or `TurnState`'s per-turn counters
 * (rule registrations, tool registrations) would not compose.
 */
class AgentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ToolRegistry::class, function ($app) {
            // FIXED spec order (§C.10) — salary_lookup / reference_fact /
            // convenio_search / national_law / ask_employee this step;
            // step 9 adds general_knowledge. `finalize`/`escalate` are
            // control tools AgentChatService itself recognizes — never
            // registry entries (§B.3.8/§B.5).
            $registry = new ToolRegistry;
            $registry->register($app->make(SalaryLookupTool::class));
            $registry->register($app->make(ReferenceFactTool::class));
            $registry->register($app->make(ConvenioSearchTool::class));
            $registry->register($app->make(NationalLawTool::class));
            // Sprint 13, step 9 (plan.md §B.6.1 condition 1): registered ONLY
            // when the lane is effectively enabled (env baseline AND the
            // Guardarraíles admin toggle) — a disabled lane's tool is not
            // even in the planner's `enabled_tools` list, never merely
            // denied at call time. `pre_call:general_knowledge` below is
            // still the belt-and-suspenders check for any stale call.
            if ($app->make(GuardrailPolicy::class)->generalLaneEnabled()) {
                $registry->register($app->make(GeneralKnowledgeTool::class));
            }
            $registry->register($app->make(AskEmployeeTool::class));

            return $registry;
        });

        $this->app->singleton(RuleEngine::class, function ($app) {
            $engine = new RuleEngine;
            $engine->register('turn_start', $app->make(PeriodSupportGuard::class));
            $engine->register('pre_call', $app->make(ClarificationBudgetRule::class));
            $engine->register('post_call:salary_lookup', $app->make(SalaryLookupPostCallRule::class));
            $engine->register('pre_call:reference_fact', $app->make(ReferenceFactSalaryPrecedenceRule::class));
            $engine->register('post_call:reference_fact', $app->make(ReferenceFactPostCallRule::class));
            // CP-2 (wt-03): pay-intent questions never go to prose; registered FIRST so it wins
            // over the national_law -> convenio_search rewrite below.
            $engine->register('pre_call:convenio_search', $app->make(SalaryIntentPreCallRule::class));
            $engine->register('pre_call:national_law', $app->make(SalaryIntentPreCallRule::class));
            $engine->register('pre_call:national_law', $app->make(NationalLawPrecedenceRule::class));
            // CP-2 (wt-03): a euro amount in synthesised prose is discarded BEFORE the
            // prose post-call rule would force-finish the answer.
            $engine->register('post_call:convenio_search', $app->make(FigureNotFromTablePostCallRule::class));
            $engine->register('post_call:national_law', $app->make(FigureNotFromTablePostCallRule::class));
            $engine->register('post_call:convenio_search', $app->make(ProseCheckAPostCallRule::class));
            $engine->register('post_call:national_law', $app->make(ProseCheckAPostCallRule::class));
            $engine->register('pre_call:general_knowledge', $app->make(GeneralLaneAvailabilityRule::class));
            // Order load-bearing: the post-check must see the candidate
            // answer FIRST and may discard it before the finish rule ever
            // forces anything (RuleEngine stops at the first non-allow).
            $engine->register('post_call:general_knowledge', $app->make(GeneralLanePostCheck::class));
            $engine->register('post_call:general_knowledge', $app->make(GeneralLaneFinishRule::class));
            $engine->register('pre_call:ask_employee', $app->make(AskEmployeeWhitelist::class));
            $engine->register('post_call:ask_employee', $app->make(AskEmployeePostCallRule::class));

            return $engine;
        });
    }
}
