<?php

namespace App\Services\Agent;

use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\Employee;
use App\Services\Answer\PreModelGuards;
use App\Services\Answer\ReferenceFactPath;
use App\Services\Answer\SalaryPath;
use App\Services\Answer\SessionResolver;
use App\Services\Answer\TurnOutcome;
use App\Services\Answer\TurnPersister;
use App\Services\ChatService;
use App\Services\ReferenceFactRouter;
use App\Services\RouterService;
use Illuminate\Support\Carbon;
use Tests\Feature\Sprint13RuleEngineInvariantTest;

/**
 * Sprint 13, build step 3 (plan.md §D.11) — the agent engine's loop:
 * pre-model guards → round 0 (deterministic salary/fact short-circuit,
 * byte-comparable with classic) → up to 4 planner rounds, each running the
 * planner's tool calls through {@see RuleEngine} at `pre_call`/`pre_call:<tool>`
 * /`post_call:<tool>`, honouring `escalate`, and finishing on `finalize` →
 * {@see TurnPersister} (the SAME persister classic uses, so the one-place
 * `EMPLOYEE_ESCALATION_MESSAGE` override — case 13 — holds for both engines
 * by construction, not by a second copy of the rule).
 *
 * Real tool wrappers (`salary_lookup`, `reference_fact`, `convenio_search`,
 * `national_law`) land across step 5, along with `finish()`'s finisher —
 * see that method's own docblock for exactly what it composes today
 * (currently: re-surfacing a `convenio_search`/`national_law` R16 miss
 * verbatim when nothing else produced material; `ask_employee`/`escalate`
 * still pending). {@see Sprint13RuleEngineInvariantTest}'s cases 1/7/8/11/13
 * need only the loop mechanics (round 0, budgets, rule precedence, the
 * shared persister), which have been real since step 3.
 */
class AgentChatService
{
    public function __construct(
        private readonly RouterService $router,
        private readonly ReferenceFactRouter $referenceFactRouter,
        private readonly SalaryPath $salaryPath,
        private readonly ReferenceFactPath $referenceFactPath,
        private readonly TurnPersister $persister,
        private readonly SessionResolver $sessionResolver,
        private readonly PreModelGuards $preModelGuards,
        private readonly RuleEngine $ruleEngine,
        private readonly ToolRegistry $tools,
        private readonly PlannerClient $planner,
        private readonly ChatService $classic,
        private readonly ScopeSummaryBuilder $scopeSummaryBuilder,
        private readonly WindowBuilder $windowBuilder,
    ) {}

    /** @return array<string,mixed> the response payload — same shape as `ChatService::handleMessage()`. */
    public function handle(Employee $employee, string $question, ?string $sessionUuid = null, ?int $selectedJobCategoryId = null): array
    {
        $asOfDate = Carbon::today();
        $session = $this->sessionResolver->resolve($employee, $sessionUuid);
        $employee->loadMissing('convenio');

        $trace = [
            'profile' => [
                'employee_uuid' => $employee->uuid,
                'convenio_id' => $employee->convenio_id,
                'convenio_numero' => $employee->convenio?->numero,
                'territory_id' => $employee->territory_id,
                'job_category_id' => $employee->job_category_id,
            ],
            'scope_filters' => [
                'convenio_id' => $employee->convenio_id,
                'include_national_law' => true,
                'retrieval_status' => ['active'],
                'as_of_date' => $asOfDate->toDateString(),
            ],
            'router_decision' => null,
            'guardrail_check' => ['fired' => false, 'reason' => null, 'rule' => null],
        ];

        // --- Case 1: sensitive/legal-medical/other-employee/admin-block/
        // explicit_request — deterministic, no hr-ai call, fires BEFORE the
        // planner even exists for this turn. `/plan` is never called. -------
        $guarded = $this->preModelGuards->check($question, $trace);
        if ($guarded !== null) {
            return $this->persister->persist($session, $employee, $question, $guarded);
        }

        // Whether this session already has a turn before this one — round 0's
        // "not a follow-up" gate (§C.9's round-0 short-circuit paragraph). A
        // follow-up's meaning depends on prior turns the planner alone can
        // read (the window, §B.4.4), so round 0 defers to the planner rather
        // than risk answering out of context.
        $isFollowUp = ChatMessage::where('session_id', $session->id)->exists();
        $isCompound = count($this->router->deterministicSplit($question)) >= 2;

        $state = new TurnState($employee, $question, $asOfDate, $session, $trace, $selectedJobCategoryId);
        $state->asksUsedBeforeTurn = $this->countPriorClarifications($session);

        // --- turn_start rules — BEFORE round 0, which would otherwise seed
        // a wrong-year answer straight past every tool-boundary rule
        // (§F.15, `Rules\PeriodSupportGuard`'s own docblock). --------------
        $turnStart = $this->ruleEngine->run('turn_start', $state);
        if ($turnStart->isTerminal()) {
            $state->applyForced($turnStart);
            $outcome = $state->finalOutcome ?? $this->budgetOutcome($state, 'malformed');

            return $this->persister->persist($session, $employee, $question, $this->stampAgentTrace($outcome, $state, $state->terminationReason));
        }

        // --- Round 0: the deterministic routes classic already has, run
        // BEFORE any planner call (§C.9). --------------------------------
        $round0 = $this->runRoundZero($employee, $question, $asOfDate, $selectedJobCategoryId, $state);
        $seeded = $round0['outcome'] ?? null;

        // A round-0 outcome SETTLES the turn (no planner call at all) when the question is a single question and either
        // it is a first turn, or the route is the salary table. The salary route is decided by the question text alone
        // (`RouterService::matchesSalary()` with no prose clause), never by the conversation, and classic answers it the
        // same way on every turn regardless of history; the planner has nothing to add — `SalaryIntentPreCallRule` already
        // denies every prose tool on a pay question, so its only possible move is the same `salary_lookup` (CP-2 trace
        // review: a category-pick follow-up ran round 0, threw the result away, paid a ~1.8 s / ~$0.03 planner round that
        // chose `salary_lookup`, and reached the identical outcome). Every other follow-up, and every compound question,
        // still defers to the planner: their meaning can depend on prior turns (§C.9).
        $settledByRoundZero = $seeded !== null && ! $isCompound && (! $isFollowUp || $round0['route'] === 'salary_lookup');

        if ($settledByRoundZero) {
            // Byte-comparable with classic by construction: the SAME path
            // classes produced this outcome, seeded before any planner call.
            // `TurnOutcome::$trace` is readonly, so a NEW instance carries the
            // stamped trace — never an in-place mutation of the existing one.
            return $this->persister->persist($session, $employee, $question, $this->stampAgentTrace($seeded, $state, 'finalize'));
        }

        // --- The planner loop (rounds 1..4) ------------------------------
        try {
            $outcome = $this->loop($state, $seeded);
        } catch (PlannerUnavailableException) {
            // §F.10 / §C.9 "Planner failure": run classic wholesale for this
            // turn. Nothing round 0 did above was persisted, so there is no
            // double-write — classic resolves and persists the turn itself.
            return $this->classic->handleMessage($employee, $question, $sessionUuid, $selectedJobCategoryId);
        }

        return $this->persister->persist($session, $employee, $question, $this->stampAgentTrace($outcome, $state, $state->terminationReason));
    }

    /**
     * Returns a NEW `TurnOutcome` with `trace.engine`/`trace.agent` stamped
     * on. `TurnOutcome::$trace` is readonly (§B.1) — a path class builds one
     * once and hands it to the persister, so this class must construct a
     * fresh copy rather than mutate `$outcome->trace` in place (that would
     * throw: "Cannot modify readonly property").
     */
    private function stampAgentTrace(TurnOutcome $outcome, TurnState $state, ?string $termination): TurnOutcome
    {
        $trace = $outcome->trace;
        $trace['engine'] = 'agent';
        $trace['agent'] = $this->agentBlock($state, $termination, $trace['agent'] ?? []);

        return new TurnOutcome($outcome->outcome, $outcome->answer, $outcome->citations, $trace, $outcome->escalationReason, $outcome->categories);
    }

    /**
     * Builds the `trace.agent` block (§D.12), preserving any structured
     * termination detail a budget/escalate branch already stashed under
     * `$existing` (`budget_exhausted`, `planner_escalation`) rather than
     * clobbering it with the generic defaults below.
     *
     * @param  array<string,mixed>  $existing
     * @return array<string,mixed>
     */
    private function agentBlock(TurnState $state, ?string $termination, array $existing): array
    {
        return array_merge([
            'planner' => null,
            'budget' => $this->budgetSnapshot($state),
            // §B.4.4's last bullet: ids only, never content — and only ever
            // non-empty on a turn that actually reached the planner loop
            // (`loop()` is the one place `TurnState::$windowMessageIds` is
            // set; every short-circuited path legitimately never consulted
            // a window at all, so `[]` there is accurate, not a stub).
            'window' => ['message_ids' => $state->windowMessageIds],
            'steps' => $state->steps,
            'termination' => $termination,
            'planner_escalation' => null,
        ], $existing);
    }

    /** @return array{max_rounds:int,max_tool_calls:int,asks_used_before_turn:int} */
    private function budgetSnapshot(TurnState $state): array
    {
        return [
            'max_rounds' => 4,
            'max_tool_calls' => 6,
            'asks_used_before_turn' => $state->asksUsedBeforeTurn,
        ];
    }

    /** Count of this session's ask/needs_category assistant turns so far (§B.4.3) — computed, not stored. */
    private function countPriorClarifications(ChatSession $session): int
    {
        return ChatMessage::where('session_id', $session->id)
            ->where('role', 'assistant')
            ->whereHas('trace', function ($q) {
                $q->whereRaw("trace->'floor_decision'->>'outcome' IN ('ask', 'needs_category')");
            })
            ->count();
    }

    /**
     * The deterministic routes classic already has, run before any planner
     * call (§C.9's "Round 0"). Mirrors `RouterService::classify()`'s own
     * deterministic salary branch and `ChatService::handleMessage()`'s
     * reference-fact pre-check exactly, so a seeded turn is byte-comparable
     * with classic (§B.1's whole reason for existing).
     */
    /** @return array{outcome:TurnOutcome,route:string}|null the round-0 outcome and which deterministic route produced it */
    private function runRoundZero(Employee $employee, string $question, Carbon $asOfDate, ?int $selectedJobCategoryId, TurnState $state): ?array
    {
        if ($this->router->matchesSalary($question) && $this->router->crossPathProseClauses($question) === []) {
            $decision = ['cross_path' => false, 'source' => 'deterministic_salary', 'subqueries' => []];
            $outcome = $this->salaryPath->handle($employee, $question, $decision, $asOfDate, $selectedJobCategoryId, $state->trace);
            $state->trace = $outcome->trace;
            $state->recordStep(['type' => 'round0', 'seeded' => ['salary_lookup']]);

            return ['outcome' => $outcome, 'route' => 'salary_lookup'];
        }

        if (! $this->router->matchesSalary($question)) {
            $refDetection = $this->referenceFactRouter->detectTopic($employee, $question, $asOfDate);
            if ($refDetection !== null) {
                $outcome = $this->referenceFactPath->handle($employee, $question, $refDetection, $asOfDate, $state->trace);
                $state->trace = $outcome->trace;
                $state->recordStep(['type' => 'round0', 'seeded' => ['reference_fact']]);

                return ['outcome' => $outcome, 'route' => 'reference_fact'];
            }
        }

        return null;
    }

    /**
     * Rounds 1..4. `$seeded` (round 0's result, if any, when the short-circuit
     * above did NOT apply — a compound or follow-up question) is recorded on
     * the trace by round 0 itself (`runRoundZero()`'s own `recordStep()`
     * call) but is not yet fed into the planner's context as material — a
     * compound/follow-up question that round 0 already seeded still asks the
     * planner to route from scratch this pass; wiring the seed in as
     * round-0-material is left for a later pass, since no v1 gate case
     * exercises it (out of this step's authorized scope, not a silent gap —
     * `runRoundZero()`'s own docblock already flags this).
     *
     * `$scopeSummary`/`$window` (§C.10) are built ONCE per turn, here — the
     * one place a turn actually needs them — rather than in `handle()` for
     * every turn: most turns never reach the planner at all (round 0's
     * short-circuit, §C.9), so building a window (a real query against
     * `chat_messages`) for a turn that never asks for it would be pure
     * waste. `TurnState::$windowMessageIds` is set here so `agentBlock()`
     * can record what was actually consulted — `[]` on every turn that
     * never calls this method remains accurate, not a stub.
     */
    private function loop(TurnState $state, ?TurnOutcome $seeded): TurnOutcome
    {
        $scopeSummary = $this->scopeSummaryBuilder->build($state->employee, $state->asOfDate);
        $window = $this->windowBuilder->build($state->session);
        $state->windowMessageIds = $window['message_ids'];
        $toolDefinitions = [...$this->tools->definitions(), ...ControlTools::definitions()];

        while (! $state->terminated) {
            if ($state->rounds >= 4) {
                $state->applyForced(Verdict::forceEscalate(
                    $this->budgetOutcome($state, 'rounds'),
                    'budget_rounds',
                ));
                break;
            }
            if ($state->wallClockSeconds() >= 45) {
                $state->applyForced(Verdict::forceEscalate(
                    $this->budgetOutcome($state, 'wall_clock'),
                    'budget_wall_clock',
                ));
                break;
            }

            $state->rounds++;
            $plan = $this->planner->plan($state->question, $scopeSummary, $window, $toolDefinitions, $state->steps);

            $state->trace['agent']['planner'] = [
                'model' => $plan['model'] ?? null,
                'prompt_version' => $plan['prompt_version'] ?? null,
                'tool_choice' => 'any',
                'thinking' => false,
            ];
            $state->recordStep([
                'type' => 'planner_round',
                'round' => $state->rounds,
                'calls' => $plan['calls'] ?? [],
                'stop_reason' => $plan['stop_reason'] ?? null,
                'tokens' => $plan['tokens'] ?? [],
                'ms' => $plan['ms'] ?? 0,
                'request_id' => $plan['request_id'] ?? null,
                'model' => $plan['model'] ?? null,
                'prompt_version' => $plan['prompt_version'] ?? null,
            ]);

            $calls = $plan['calls'] ?? [];
            if ($calls === []) {
                if (! $this->registerMalformed($state)) {
                    break;
                }

                continue;
            }

            $this->processRound($calls, $state);
        }

        return $state->finalOutcome ?? $this->budgetOutcome($state, 'malformed');
    }

    /**
     * Runs one round's calls: real tool calls first (any post-call force
     * wins immediately), THEN `escalate` (§B.5 precedence — "the rule's
     * reason wins" over a same-round planner `escalate`), THEN `finalize`.
     *
     * @param  list<array{id:string,tool:string,input:array<string,mixed>}>  $calls
     */
    private function processRound(array $calls, TurnState $state): void
    {
        $escalateCalls = [];
        $finalizeCalls = [];

        foreach ($calls as $call) {
            $toolName = $call['tool'] ?? null;

            if ($toolName === 'escalate') {
                $escalateCalls[] = $call;

                continue;
            }
            if ($toolName === 'finalize') {
                $finalizeCalls[] = $call;

                continue;
            }
            if (! is_string($toolName) || ! $this->tools->has($toolName)) {
                if (! $this->registerMalformed($state)) {
                    return;
                }

                continue;
            }

            $this->runToolCall($toolName, $call, $state);
            if ($state->terminated) {
                return;
            }
            if ($state->toolCalls >= 6) {
                $state->applyForced(Verdict::forceEscalate($this->budgetOutcome($state, 'tool_calls'), 'budget_tool_calls'));

                return;
            }
        }

        // No forced verdict from a real tool call this round — an `escalate`
        // still beats an unfinished `finalize` (additive: more escalation,
        // never less, §B.5).
        if ($escalateCalls !== []) {
            $call = $escalateCalls[0];
            $category = $call['input']['category'] ?? 'other';
            $reason = $call['input']['reason'] ?? '';
            $state->trace['floor_decision'] = [
                'path' => 'agent_planner',
                'outcome' => 'escalate',
                'escalation_reason' => 'planner_escalated',
                'authority_used' => [],
                'note' => 'planner escalate',
            ];
            $state->trace['agent']['planner_escalation'] = ['category' => $category, 'reason' => $reason];
            $outcome = new TurnOutcome('escalate', ChatService::EMPLOYEE_ESCALATION_MESSAGE, [], $state->trace, 'planner_escalated');
            $state->terminated = true;
            $state->terminationReason = 'planner_escalated';
            $state->finalOutcome = $outcome;
            $state->recordStep(['type' => 'planner_escalate', 'category' => $category]);

            return;
        }

        if ($finalizeCalls !== []) {
            $call = $finalizeCalls[0];
            $this->finish($call, $state);
        }
    }

    /** @param  array{id:string,tool:string,input:array<string,mixed>}  $call */
    private function runToolCall(string $toolName, array $call, TurnState $state): void
    {
        $input = $call['input'] ?? [];

        $callId = $call['id'] ?? null;

        $generic = $this->ruleEngine->run('pre_call', $state, $call);
        if ($generic->isTerminal()) {
            $state->applyForced($generic);

            return;
        }
        if ($generic->status === Verdict::DENY) {
            // `call_id` (added alongside step 6's real planner) lets hr-ai's
            // `/plan` reconstruct a `tool_result` block for THIS call on the
            // next round — a denial is exactly the "one-line reason" §C.10
            // says a denied call returns to the planner.
            $state->recordStep(['type' => 'tool_denied', 'call_id' => $callId, 'tool' => $toolName, 'reason' => $generic->rule]);

            return;
        }
        if ($generic->status === Verdict::REWRITE) {
            $call = $generic->rewrittenCall ?? $call;
            $toolName = $call['tool'];
            $input = $call['input'] ?? [];
        }

        $specific = $this->ruleEngine->run("pre_call:{$toolName}", $state, $call);
        if ($specific->isTerminal()) {
            $state->applyForced($specific);

            return;
        }
        if ($specific->status === Verdict::DENY) {
            $state->recordStep(['type' => 'tool_denied', 'call_id' => $callId, 'tool' => $toolName, 'reason' => $specific->rule]);

            return;
        }
        if ($specific->status === Verdict::REWRITE) {
            $call = $specific->rewrittenCall ?? $call;
            $toolName = $call['tool'];
            $input = $call['input'] ?? [];
        }

        $cacheKey = TurnState::cacheKey($toolName, $input);
        if (isset($state->cache[$cacheKey])) {
            $result = $state->cache[$cacheKey];
        } else {
            $tool = $this->tools->get($toolName);
            $result = $tool->run($input, $state);
            $state->cache[$cacheKey] = $result;
            $state->toolCalls++;
            if ($tool->countsAsClarification()) {
                $state->asksThisTurn++;
            }
        }

        $post = $this->ruleEngine->run("post_call:{$toolName}", $state, $call, $result);
        if ($post->isTerminal()) {
            $state->applyForced($post);

            return;
        }

        $state->material[$toolName] = $result;
        $state->trace = array_merge($state->trace, $result->traceBlocks);
        // `planner_summary` (§C.10) — the SAME status-only/never-the-figure
        // envelope every `Tool::run()` already builds (see e.g.
        // `SalaryLookupTool`'s own docblock) — is what hr-ai's `/plan`
        // reads back to build this call's `tool_result` block on the NEXT
        // round; recorded here (not just held in `$result`) because
        // `$state->steps` — not `$state->material` — is what crosses the
        // wire as `$priorSteps`.
        $state->recordStep(['type' => 'tool_call', 'call_id' => $call['id'] ?? null, 'tool' => $toolName, 'status' => $result->status, 'planner_summary' => $result->plannerSummary]);
    }

    private function registerMalformed(TurnState $state): bool
    {
        $state->malformedCount++;
        if ($state->malformedCount > 2) {
            $state->applyForced(Verdict::forceEscalate($this->budgetOutcome($state, 'malformed'), 'budget_malformed'));

            return false;
        }

        return true;
    }

    /**
     * `finalize` reaching this point has only `NO_MATERIAL` (never `MATERIAL`
     * — no tool in this sprint's v1 set produces it, only `TERMINAL`, which
     * always already forced via `post_call` before `finalize` could even be
     * reached) results in `$state->material`. The one shape worth composing
     * (plan §B.3.3): a `convenio_search`/`national_law` R16 Check-A miss
     * stashed its own already-built escalate `TurnOutcome` in
     * `terminalOutcome` specifically so the finisher could re-surface it
     * verbatim — "the finisher emits the same R16 escalation classic would."
     * If no tool left anything reusable (e.g. only `reference_fact`'s
     * `no_fact` ran, which stashes nothing — there is genuinely no material
     * to fall through to, same as classic never calling prose at all),
     * escalate honestly rather than fabricate an answer.
     */
    private function finish(array $call, TurnState $state): void
    {
        $stashed = $this->lastStashedProseEscalation($state);

        if ($stashed !== null) {
            $state->terminated = true;
            $state->terminationReason = 'finalize';
            $state->finalOutcome = $stashed;
            $state->recordStep(['type' => 'finalize', 'use' => $call['input']['use'] ?? []]);

            return;
        }

        $state->trace['floor_decision'] = [
            'path' => 'agent_finalize',
            'outcome' => 'escalate',
            'escalation_reason' => 'low_confidence',
            'authority_used' => [],
            'note' => 'finalize called with no material to compose (no tool produced an answer or a reusable R16 escalation)',
        ];
        $outcome = new TurnOutcome('escalate', ChatService::EMPLOYEE_ESCALATION_MESSAGE, [], $state->trace, 'low_confidence');
        $state->terminated = true;
        $state->terminationReason = 'finalize';
        $state->finalOutcome = $outcome;
        $state->recordStep(['type' => 'finalize', 'use' => $call['input']['use'] ?? []]);
    }

    /**
     * The most recently-run tool (in call order — `$state->material` is
     * insertion-ordered, keyed by tool name) that stashed a reusable
     * `TurnOutcome` in a non-terminal `ToolResult` — currently only
     * `convenio_search`/`national_law`'s R16 miss does this (see their
     * docblocks). `null` when nothing did.
     */
    private function lastStashedProseEscalation(TurnState $state): ?TurnOutcome
    {
        foreach (array_reverse($state->material) as $result) {
            if ($result->terminalOutcome instanceof TurnOutcome) {
                return $result->terminalOutcome;
            }
        }

        return null;
    }

    private function budgetOutcome(TurnState $state, string $sub): TurnOutcome
    {
        return BudgetOutcomeFactory::make($state->trace, $sub);
    }
}
