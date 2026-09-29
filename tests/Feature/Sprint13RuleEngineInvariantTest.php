<?php

namespace Tests\Feature;

use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\Convenio;
use App\Models\Employee;
use App\Models\MessageTrace;
use App\Models\Sector;
use App\Models\Territory;
use App\Services\Agent\AgentChatService;
use App\Services\Agent\PlannerClient;
use App\Services\Agent\PlannerUnavailableException;
use App\Services\Agent\Rule;
use App\Services\Agent\RuleEngine;
use App\Services\Agent\Rules\ClarificationBudgetRule;
use App\Services\Agent\Rules\GeneralLaneAvailabilityRule;
use App\Services\Agent\Rules\GeneralLanePostCheck;
use App\Services\Agent\Tool;
use App\Services\Agent\ToolRegistry;
use App\Services\Agent\ToolResult;
use App\Services\Agent\TurnState;
use App\Services\Agent\Verdict;
use App\Services\Answer\TurnOutcome;
use App\Services\ChatService;
use App\Services\GuardrailPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Sprint 13, build step 3 (plan.md §D.11) — "the planner cannot suppress a
 * forced escalation" as a structural guarantee, not a prompting convention.
 * Covers cases 1, 7, 8, 11, 13 of §D.11's 13-case list (the rest need real
 * tools/wrappers from step 5 onward and are deferred there).
 *
 * Every scenario runs `AgentChatService::handle()` directly (never through
 * `AnswerEngineDispatcher`/HTTP — the loop mechanics under test don't need
 * the switch), with a scripted {@see PlannerClient} and, where a case needs
 * one, fake {@see Tool}/{@see Rule} objects registered directly against
 * fresh `ToolRegistry`/`RuleEngine` singletons for that one test — mirroring
 * `Sprint7cAdditivityRegressionTest`'s fake `ExtractionClient` pattern, and
 * the plan's own description of "'exploding' stubs that fail the test if
 * called" for the negative half of each assertion.
 */
class Sprint13RuleEngineInvariantTest extends TestCase
{
    use RefreshDatabase;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $territory = Territory::create(['code' => '01', 'name' => 'Álava', 'level' => 'provincial', 'aliases' => []]);
        $sector = Sector::create(['name' => 'Sector', 'aliases' => []]);
        $convenio = Convenio::create(['numero' => '13RULX0001', 'name' => 'Rule Engine Test', 'territory_id' => $territory->id, 'sector_id' => $sector->id]);
        $this->employee = Employee::create([
            'email' => 'rule-engine@example.com', 'full_name' => 'Rule Engine',
            'convenio_id' => $convenio->id, 'territory_id' => $territory->id,
            'employment_type' => 'full_time', 'status' => 'active',
        ]);
    }

    /**
     * Rebinds `ToolRegistry`/`RuleEngine`/`PlannerClient` for ONE test and
     * resolves a fresh `AgentChatService` against them. `$extraRules` is a
     * list of `[boundary, Rule]` pairs registered AFTER the real
     * `ClarificationBudgetRule` (§C.9) that ships in `AgentServiceProvider`,
     * so every case still exercises the real budget rule unless a case
     * deliberately wants to test around it.
     *
     * @param  list<Tool>  $tools
     * @param  list<array{0:string,1:Rule}>  $extraRules
     */
    private function agentWith(PlannerClient $planner, array $tools = [], array $extraRules = []): AgentChatService
    {
        $this->app->singleton(ToolRegistry::class, function () use ($tools) {
            $registry = new ToolRegistry;
            foreach ($tools as $tool) {
                $registry->register($tool);
            }

            return $registry;
        });

        $this->app->singleton(RuleEngine::class, function ($app) use ($extraRules) {
            $engine = new RuleEngine;
            $engine->register('pre_call', $app->make(ClarificationBudgetRule::class));
            foreach ($extraRules as [$boundary, $rule]) {
                $engine->register($boundary, $rule);
            }

            return $engine;
        });

        $this->app->bind(PlannerClient::class, fn () => $planner);

        return $this->app->make(AgentChatService::class);
    }

    // ---- Case 1: sensitive question -> /plan never called -------------

    public function test_case1_sensitive_question_never_calls_the_planner(): void
    {
        $agent = $this->agentWith(new ExplodingPlannerClient);

        $response = $agent->handle($this->employee, 'Estoy sufriendo acoso en el trabajo, ¿qué hago?');

        $this->assertTrue($response['escalated']);
        $this->assertSame('sensitive_topic', $response['escalation_reason']);
        $this->assertSame(ChatService::EMPLOYEE_ESCALATION_MESSAGE, $response['answer']);
    }

    // ---- Case 7: third clarification -> tool_budget_exhausted ---------

    public function test_case7_third_clarification_forces_budget_exhausted_without_running_the_tool(): void
    {
        $session = ChatSession::create(['employee_id' => $this->employee->id, 'started_at' => now(), 'last_activity_at' => now()]);
        $this->seedPriorClarifications($session, 2);

        $tool = new FakeAgentTool('ask_employee', countsAsClarification: true, run: function (): ToolResult {
            throw new RuntimeException('ask_employee::run() must never be called once the clarification budget is already exhausted');
        });

        $planner = new ScriptedPlannerClient([
            ['calls' => [['id' => 't1', 'tool' => 'ask_employee', 'input' => []]]],
        ]);

        $agent = $this->agentWith($planner, [$tool]);

        $response = $agent->handle($this->employee, '¿Qué me corresponde según mi convenio?', $session->uuid);

        $this->assertTrue($response['escalated']);
        $this->assertSame('tool_budget_exhausted', $response['escalation_reason']);
        $this->assertSame('clarifications', $response['trace']['agent']['budget_exhausted']['sub'] ?? null);
        $this->assertSame(ChatService::EMPLOYEE_ESCALATION_MESSAGE, $response['answer']);
    }

    // ---- Case 8: planner never finalizes -> exactly 4 rounds then budget --

    public function test_case8_planner_never_finalizing_stops_at_exactly_four_rounds(): void
    {
        $calls = 0;
        $probe = new FakeAgentTool('probe', countsAsClarification: false, run: function () use (&$calls): ToolResult {
            $calls++;

            return new ToolResult(ToolResult::MATERIAL, material: ['n' => $calls]);
        });

        // Distinct input per round — a real planner would vary what it asks
        // for; a fixed input would legitimately hit `TurnState`'s own
        // identical-call cache (§C.9) and only run the tool once, which
        // would test the CACHE, not the round budget this case is about.
        $round = fn (int $i) => ['calls' => [['id' => "t{$i}", 'tool' => 'probe', 'input' => ['round' => $i]]]];
        $planner = new ScriptedPlannerClient([$round(1), $round(2), $round(3), $round(4), $round(5), $round(6)]);

        $agent = $this->agentWith($planner, [$probe]);

        $response = $agent->handle($this->employee, 'Una pregunta compuesta que necesita varios pasos, y también otra cosa');

        $this->assertTrue($response['escalated']);
        $this->assertSame('tool_budget_exhausted', $response['escalation_reason']);
        $this->assertSame('rounds', $response['trace']['agent']['budget_exhausted']['sub'] ?? null);
        $this->assertSame(4, $planner->callsMade(), 'the planner must be asked for exactly 4 rounds, never a 5th');
        $this->assertSame(4, $calls, 'each of the 4 rounds ran the probe tool exactly once');
    }

    // ---- Case 9: general_knowledge denied while the lane is off -----------

    public function test_case9_general_knowledge_is_denied_and_never_executed_while_the_lane_is_off(): void
    {
        config(['hr.general_lane.enabled' => false]);
        GuardrailPolicy::flush();

        $tool = new FakeAgentTool('general_knowledge', countsAsClarification: false, run: function (): ToolResult {
            throw new RuntimeException('general_knowledge::run() must never be called while the lane is disabled');
        });

        $planner = new ScriptedPlannerClient([
            ['calls' => [['id' => 't1', 'tool' => 'general_knowledge', 'input' => []]]],
            ['calls' => [['id' => 't2', 'tool' => 'escalate', 'input' => ['category' => 'unanswerable', 'reason' => 'sin material']]]],
        ]);

        $agent = $this->agentWith($planner, [$tool], [
            ['pre_call:general_knowledge', $this->app->make(GeneralLaneAvailabilityRule::class)],
        ]);

        $response = $agent->handle($this->employee, '¿Qué es una excedencia?');

        $this->assertTrue($response['escalated']);
        $deniedSteps = array_values(array_filter(
            $response['trace']['agent']['steps'],
            fn (array $s) => ($s['type'] ?? null) === 'tool_denied' && ($s['tool'] ?? null) === 'general_knowledge',
        ));
        $this->assertCount(1, $deniedSteps, 'expected exactly one tool_denied step for general_knowledge');
        $this->assertSame('general_lane_availability', $deniedSteps[0]['reason']);
    }

    // ---- Case 10: a general-lane answer with a smuggled figure/entitlement ----

    public function test_case10_general_lane_answer_with_a_figure_and_entitlement_is_blocked(): void
    {
        $leakyOutcome = new TurnOutcome(
            'answer',
            'Tienes derecho a quince días, según se explica de forma general.',
            [],
            ['floor_decision' => ['path' => 'general_knowledge', 'outcome' => 'answer', 'authority_used' => ['general_knowledge']]],
            null,
        );
        $tool = new FakeAgentTool('general_knowledge', countsAsClarification: false, run: fn () => new ToolResult(
            ToolResult::TERMINAL,
            terminalOutcome: $leakyOutcome,
            plannerSummary: ['status' => 'answer'],
        ));

        $planner = new ScriptedPlannerClient([
            ['calls' => [['id' => 't1', 'tool' => 'general_knowledge', 'input' => []]]],
        ]);

        $agent = $this->agentWith($planner, [$tool], [
            ['post_call:general_knowledge', $this->app->make(GeneralLanePostCheck::class)],
        ]);

        $response = $agent->handle($this->employee, '¿Qué es una excedencia?');

        $this->assertTrue($response['escalated']);
        $this->assertSame('general_lane_blocked', $response['escalation_reason']);
        $this->assertSame(ChatService::EMPLOYEE_ESCALATION_MESSAGE, $response['answer'], 'the leaky lane answer must never reach the employee');
    }

    // ---- Case 11: planner escalate in the same round as a forced rule -----

    public function test_case11_rule_forced_escalation_wins_over_a_same_round_planner_escalate(): void
    {
        $ruleOutcome = new TurnOutcome(
            'escalate',
            ChatService::ESCALATION_MESSAGE,
            [],
            ['floor_decision' => ['path' => 'test_rule', 'outcome' => 'escalate', 'escalation_reason' => 'estatuto_fallback_gap', 'authority_used' => []]],
            'estatuto_fallback_gap',
        );
        $forcingRule = new CallableRule('test_forcing_rule', fn () => Verdict::forceEscalate($ruleOutcome, 'test_forcing_rule'));

        $gateTool = new FakeAgentTool('gate_tool', countsAsClarification: false, run: fn () => new ToolResult(ToolResult::MATERIAL, material: []));

        // Same round: a real tool call whose post_call rule forces AND a
        // planner `escalate` call. Per §B.5/D.11 case 11, the rule's reason
        // must win — the planner's `escalate` in the SAME round is discarded.
        $planner = new ScriptedPlannerClient([
            ['calls' => [
                ['id' => 't1', 'tool' => 'gate_tool', 'input' => []],
                ['id' => 't2', 'tool' => 'escalate', 'input' => ['category' => 'unsafe', 'reason' => 'planner thinks this is unsafe']],
            ]],
        ]);

        $agent = $this->agentWith($planner, [$gateTool], [['post_call:gate_tool', $forcingRule]]);

        $response = $agent->handle($this->employee, 'Una pregunta compuesta que necesita comprobar algo y también otra cosa');

        $this->assertTrue($response['escalated']);
        $this->assertSame('estatuto_fallback_gap', $response['escalation_reason'], "the rule's reason must win over the same-round planner escalate");
        $this->assertNotSame('planner_escalated', $response['escalation_reason']);
    }

    // ---- Case 13: every agent escalation -> EMPLOYEE_ESCALATION_MESSAGE ---

    public function test_case13_every_agent_escalation_uses_the_byte_identical_employee_message(): void
    {
        $scenarios = [
            fn () => $this->agentWith(new ExplodingPlannerClient)
                ->handle($this->employee, 'Estoy sufriendo acoso en el trabajo, ¿qué hago?'),
            function () {
                $probe = new FakeAgentTool('probe', countsAsClarification: false, run: fn () => new ToolResult(ToolResult::MATERIAL, material: []));
                $round = fn (int $i) => ['calls' => [['id' => "t{$i}", 'tool' => 'probe', 'input' => ['round' => $i]]]];
                $planner = new ScriptedPlannerClient([$round(1), $round(2), $round(3), $round(4), $round(5)]);

                return $this->agentWith($planner, [$probe])
                    ->handle($this->employee, 'Una pregunta compuesta que necesita varios pasos, y también otra cosa');
            },
        ];

        foreach ($scenarios as $i => $scenario) {
            $response = $scenario();
            $this->assertTrue($response['escalated'], "scenario {$i} was expected to escalate");
            $this->assertSame(
                ChatService::EMPLOYEE_ESCALATION_MESSAGE,
                $response['answer'],
                "scenario {$i}'s employee-visible answer must be byte-identical to EMPLOYEE_ESCALATION_MESSAGE regardless of internal escalation_reason ('{$response['escalation_reason']}')"
            );
        }
    }

    private function seedPriorClarifications(ChatSession $session, int $n): void
    {
        for ($i = 0; $i < $n; $i++) {
            ChatMessage::create(['session_id' => $session->id, 'role' => 'user', 'content' => 'previous question '.$i]);
            $assistant = ChatMessage::create(['session_id' => $session->id, 'role' => 'assistant', 'content' => 'previous clarification '.$i]);
            MessageTrace::create(['message_id' => $assistant->id, 'trace' => ['floor_decision' => ['outcome' => 'ask']]]);
        }
    }
}

/** Throws if `plan()` is ever called — the negative half of case 1's assertion. */
final class ExplodingPlannerClient implements PlannerClient
{
    public function plan(string $question, array $scopeSummary, array $window, array $toolDefinitions, array $priorSteps): array
    {
        throw new RuntimeException('PlannerClient::plan() must never be called for this scenario');
    }
}

/** A fixed queue of scripted `/plan` responses, returned one per call; throws `PlannerUnavailableException` if exhausted. */
final class ScriptedPlannerClient implements PlannerClient
{
    private int $index = 0;

    /** @param  list<array<string,mixed>>  $responses */
    public function __construct(private readonly array $responses) {}

    public function plan(string $question, array $scopeSummary, array $window, array $toolDefinitions, array $priorSteps): array
    {
        if (! isset($this->responses[$this->index])) {
            throw new PlannerUnavailableException('ScriptedPlannerClient queue exhausted after '.$this->index.' call(s)');
        }

        $response = $this->responses[$this->index];
        $this->index++;

        return $response + ['stop_reason' => 'tool_use', 'model' => null, 'request_id' => null, 'prompt_version' => null, 'tokens' => [], 'ms' => 0];
    }

    public function callsMade(): int
    {
        return $this->index;
    }
}

/** A minimal, fully-scripted `Tool` — `$run` decides what `run()` returns (or throws, for an "exploding" tool). */
final class FakeAgentTool implements Tool
{
    /** @param  \Closure(array<string,mixed>, TurnState):ToolResult  $run */
    public function __construct(
        private readonly string $toolName,
        private readonly bool $countsAsClarification,
        private readonly \Closure $run,
    ) {}

    public function name(): string
    {
        return $this->toolName;
    }

    public function definition(): array
    {
        return ['name' => $this->toolName, 'description' => 'test double', 'input_schema' => ['type' => 'object']];
    }

    public function countsAsClarification(): bool
    {
        return $this->countsAsClarification;
    }

    public function run(array $input, TurnState $state): ToolResult
    {
        return ($this->run)($input, $state);
    }
}

/** A minimal `Rule` whose verdict is a fixed closure — for scripting a forced verdict at a specific boundary. */
final class CallableRule implements Rule
{
    /** @param  \Closure():Verdict  $verdict */
    public function __construct(private readonly string $ruleId, private readonly \Closure $verdict) {}

    public function id(): string
    {
        return $this->ruleId;
    }

    public function evaluate(TurnState $state, ?array $call, ?ToolResult $result): Verdict
    {
        return ($this->verdict)();
    }
}
