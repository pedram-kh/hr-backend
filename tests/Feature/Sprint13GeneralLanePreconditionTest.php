<?php

namespace Tests\Feature;

use App\Models\ChatSession;
use App\Models\Convenio;
use App\Models\Employee;
use App\Models\Sector;
use App\Models\Territory;
use App\Services\Agent\AgentChatService;
use App\Services\Agent\PlannerClient;
use App\Services\Agent\PlannerUnavailableException;
use App\Services\Agent\RuleEngine;
use App\Services\Agent\Rules\ClarificationBudgetRule;
use App\Services\Agent\Rules\CorpusMiss;
use App\Services\Agent\Rules\GeneralLaneAvailabilityRule;
use App\Services\Agent\Rules\GeneralLaneFinishRule;
use App\Services\Agent\Rules\ProseCheckAPostCallRule;
use App\Services\Agent\Tool;
use App\Services\Agent\ToolRegistry;
use App\Services\Agent\ToolResult;
use App\Services\Agent\Tools\ConvenioSearchTool;
use App\Services\Agent\Tools\NationalLawTool;
use App\Services\Agent\TurnState;
use App\Services\Answer\ProsePath;
use App\Services\Answer\TurnOutcome;
use App\Services\ChatService;
use App\Services\GuardrailPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Sprint 13 CP-1 decision (F.8 amendment, plan.md §B.6.1 condition 2):
 * `general_knowledge` may open after Check A PASSED and the answer failed ONLY
 * the per-claim entailment gate — never after a figure-guard / Check-B /
 * truncation / aggregation / fallback verdict, only for questions passing the
 * explanatory pre-screen, and with the lane off (default) nothing changes.
 */
class Sprint13GeneralLanePreconditionTest extends TestCase
{
    use RefreshDatabase;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $territory = Territory::create(['code' => '01', 'name' => 'Álava', 'level' => 'provincial', 'aliases' => []]);
        $sector = Sector::create(['name' => 'Sector', 'aliases' => []]);
        $convenio = Convenio::create(['numero' => '13LANEX001', 'name' => 'Lane Precondition Test', 'territory_id' => $territory->id, 'sector_id' => $sector->id]);
        $this->employee = Employee::create([
            'email' => 'lane-pre@example.com', 'full_name' => 'Lane Pre',
            'convenio_id' => $convenio->id, 'territory_id' => $territory->id,
            'employment_type' => 'full_time', 'status' => 'active',
        ]);

        config(['hr.general_lane.enabled' => true]);
        GuardrailPolicy::flush();
    }

    // ---- fixtures ------------------------------------------------------

    /** @param  array<string,mixed>  $override */
    private function entailmentFloor(array $override = []): array
    {
        return array_replace_recursive([
            'retrieval_score_floor' => 0.4,
            'answer_confidence_floor' => 0.65,
            'check_a_retrieval' => true,
            'check_b_citations' => true,
            'check_c_confidence_tiebreaker' => ['confidence' => 0.85, 'below_floor' => false, 'used_as_gate' => false],
            'figure_grounding' => ['checked' => true, 'figures' => ['15 días'], 'grounded' => true, 'ungrounded' => []],
            'grounding' => [
                'checked' => true, 'grounded' => false, 'claims' => [],
                'ungrounded' => ['La excedencia es una situación en la que se suspende la relación laboral.'],
                'error' => null, 'gate' => 'entailment', 'trace_fragment' => [],
            ],
            'authority_used' => ['official_convenio', 'national_law'],
            'outcome' => 'escalate',
            'escalation_reason' => 'low_confidence',
            'note' => 'ungrounded claim (per-claim entailment gate)',
        ], $override);
    }

    private function outcome(array $floor): TurnOutcome
    {
        return new TurnOutcome('escalate', ChatService::ESCALATION_MESSAGE, [], ['floor_decision' => $floor, 'retrieval' => []], 'low_confidence');
    }

    // ---- CorpusMiss::classify: a whitelist -----------------------------

    public function test_classify_recognises_exactly_the_check_a_miss_and_the_entailment_only_failure(): void
    {
        $this->assertSame(CorpusMiss::CHECK_A_MISS, CorpusMiss::classify($this->outcome([
            'check_a_retrieval' => false, 'outcome' => 'escalate', 'escalation_reason' => 'low_confidence',
        ])));
        $this->assertSame(CorpusMiss::ENTAILMENT_ONLY, CorpusMiss::classify($this->outcome($this->entailmentFloor())));
    }

    /** @return array<string,array{0:array<string,mixed>}> */
    public static function neverOpensTheLane(): array
    {
        return [
            'figure-guard failure (short-circuits /ground)' => [[
                'figure_grounding' => ['grounded' => false, 'ungrounded' => ['20 días']],
                'grounding' => ['checked' => false, 'reason' => 'short-circuited before /ground', 'grounded' => null, 'gate' => null, 'ungrounded' => null],
            ]],
            'figure-guard failure even if /ground somehow also ran' => [[
                'figure_grounding' => ['grounded' => false, 'ungrounded' => ['20 días']],
            ]],
            'check B (no valid citations)' => [['check_b_citations' => false, 'grounding' => ['checked' => false, 'grounded' => null, 'gate' => null, 'ungrounded' => null]]],
            'grounding truncated' => [['grounding' => ['ungrounded' => ['<grounding check truncated>'], 'trace_fragment' => ['grounding_truncated' => true]]]],
            'grounding unparseable' => [['grounding' => ['ungrounded' => ['<grounding check unparseable>'], 'trace_fragment' => ['parse_error' => true]]]],
            'grounding provider error' => [['grounding' => ['error' => 'boom']]],
            'different escalation reason' => [['escalation_reason' => 'estatuto_fallback_gap']],
        ];
    }

    /** @param  array<string,mixed>  $override */
    #[DataProvider('neverOpensTheLane')]
    public function test_classify_returns_null_for_every_other_post_check_a_verdict(array $override): void
    {
        $this->assertNull(CorpusMiss::classify($this->outcome($this->entailmentFloor($override))));
    }

    public function test_classify_returns_null_for_the_aggregation_provider_error_and_answer_shapes(): void
    {
        // "Entailment failed" but no ungrounded claim listed is not a real verdict.
        $floor = $this->entailmentFloor();
        $floor['grounding']['ungrounded'] = [];
        $this->assertNull(CorpusMiss::classify($this->outcome($floor)));

        // Aggregation guard: no check_a/grounding keys at all.
        $this->assertNull(CorpusMiss::classify($this->outcome(['path' => 'prose', 'outcome' => 'escalate', 'escalation_reason' => 'low_confidence', 'note' => 'aggregation'])));
        // Provider error / model not configured: check A true, no check_b, no grounding.
        $this->assertNull(CorpusMiss::classify($this->outcome([
            'check_a_retrieval' => true, 'outcome' => 'escalate', 'escalation_reason' => 'low_confidence', 'note' => 'provider error',
        ])));
        $this->assertNull(CorpusMiss::classify(new TurnOutcome('answer', 'x', [], ['floor_decision' => $this->entailmentFloor()], null)));
        $this->assertNull(CorpusMiss::classify(new TurnOutcome('escalate', 'x', [], [], 'low_confidence')));
    }

    // ---- CorpusMiss::laneMayTakeOver -----------------------------------

    public function test_lane_takes_over_only_when_enabled_and_the_question_is_explanatory(): void
    {
        $policy = $this->app->make(GuardrailPolicy::class);
        $entail = $this->outcome($this->entailmentFloor());

        $this->assertTrue(CorpusMiss::laneMayTakeOver($entail, '¿Qué es una excedencia?', $policy));

        // Entitlement / quantity phrasing → pre-screen hit → no hand-over.
        $this->assertFalse(CorpusMiss::laneMayTakeOver($entail, '¿Cuántos días de excedencia me corresponden?', $policy));
        $this->assertFalse(CorpusMiss::laneMayTakeOver($entail, '¿Tengo derecho a excedencia?', $policy));

        // Lane off (env baseline) → no hand-over: today's behaviour, byte for byte.
        config(['hr.general_lane.enabled' => false]);
        GuardrailPolicy::flush();
        $this->assertFalse(CorpusMiss::laneMayTakeOver($entail, '¿Qué es una excedencia?', $this->app->make(GuardrailPolicy::class)));
    }

    // ---- Tool wiring ---------------------------------------------------

    private function turnState(string $question): TurnState
    {
        $session = ChatSession::create(['employee_id' => $this->employee->id, 'started_at' => now(), 'last_activity_at' => now()]);

        return new TurnState($this->employee, $question, Carbon::today(), $session, []);
    }

    /** @return array<string,array{0:class-string}> */
    public static function proseTools(): array
    {
        return ['convenio_search' => [ConvenioSearchTool::class], 'national_law' => [NationalLawTool::class]];
    }

    /** @param  class-string  $toolClass */
    #[DataProvider('proseTools')]
    public function test_prose_tools_hand_an_entailment_only_failure_to_the_planner_when_the_lane_is_on(string $toolClass): void
    {
        $outcome = $this->outcome($this->entailmentFloor());
        $this->mock(ProsePath::class, fn ($m) => $m->shouldReceive('handle')->once()->andReturn($outcome));

        $result = $this->app->make($toolClass)->run([], $this->turnState('¿Qué es una excedencia?'));

        $this->assertSame(ToolResult::NO_MATERIAL, $result->status);
        $this->assertSame('entailment_failed', $result->plannerSummary['status']);
        $this->assertSame($outcome, $result->terminalOutcome, 'the corpus escalation is stashed for the finisher');
        $this->assertSame(
            ['La excedencia es una situación en la que se suspende la relación laboral.'],
            $result->traceBlocks['general_lane_precondition']['ungrounded_claims'],
        );
    }

    /** @param  class-string  $toolClass */
    #[DataProvider('proseTools')]
    public function test_prose_tools_stay_terminal_when_the_lane_is_off_or_the_question_is_a_quantity(string $toolClass): void
    {
        $outcome = $this->outcome($this->entailmentFloor());
        $this->mock(ProsePath::class, fn ($m) => $m->shouldReceive('handle')->twice()->andReturn($outcome));

        // Prescreen hit, lane on.
        $result = $this->app->make($toolClass)->run([], $this->turnState('¿Cuántos días de excedencia me corresponden?'));
        $this->assertSame(ToolResult::TERMINAL, $result->status);

        // Lane off.
        config(['hr.general_lane.enabled' => false]);
        GuardrailPolicy::flush();
        $result = $this->app->make($toolClass)->run([], $this->turnState('¿Qué es una excedencia?'));
        $this->assertSame(ToolResult::TERMINAL, $result->status);
        $this->assertSame('escalate', $result->plannerSummary['status']);
    }

    /** @param  class-string  $toolClass */
    #[DataProvider('proseTools')]
    public function test_prose_tools_stay_terminal_for_a_figure_guard_failure_even_with_the_lane_on(string $toolClass): void
    {
        $outcome = $this->outcome($this->entailmentFloor([
            'figure_grounding' => ['grounded' => false, 'ungrounded' => ['20 días']],
            'grounding' => ['checked' => false, 'grounded' => null, 'gate' => null, 'ungrounded' => null],
            'note' => 'answer figure not grounded in cited chunk (figure-guard pre-check)',
        ]));
        $this->mock(ProsePath::class, fn ($m) => $m->shouldReceive('handle')->once()->andReturn($outcome));

        $result = $this->app->make($toolClass)->run([], $this->turnState('¿Qué es una excedencia?'));

        $this->assertSame(ToolResult::TERMINAL, $result->status);
    }

    // ---- The rule, end to end through the loop -------------------------

    private function agentWith(PlannerClient $planner, Tool ...$tools): AgentChatService
    {
        $this->app->singleton(ToolRegistry::class, function () use ($tools) {
            $registry = new ToolRegistry;
            foreach ($tools as $tool) {
                $registry->register($tool);
            }

            return $registry;
        });
        $this->app->singleton(RuleEngine::class, function ($app) {
            $engine = new RuleEngine;
            $engine->register('pre_call', $app->make(ClarificationBudgetRule::class));
            $engine->register('post_call:convenio_search', $app->make(ProseCheckAPostCallRule::class));
            $engine->register('pre_call:general_knowledge', $app->make(GeneralLaneAvailabilityRule::class));
            $engine->register('post_call:general_knowledge', $app->make(GeneralLaneFinishRule::class));

            return $engine;
        });
        $this->app->bind(PlannerClient::class, fn () => $planner);

        return $this->app->make(AgentChatService::class);
    }

    /** A stand-in `convenio_search` that yields whatever stash the case needs. */
    private function corpusTool(TurnOutcome $stash): Tool
    {
        return new LanePreconditionFakeTool('convenio_search', fn () => new ToolResult(
            ToolResult::NO_MATERIAL,
            terminalOutcome: $stash,
            plannerSummary: ['status' => 'entailment_failed'],
        ));
    }

    /** @param  list<string>  $ran */
    private function laneTool(array &$ran): Tool
    {
        return new LanePreconditionFakeTool('general_knowledge', function () use (&$ran): ToolResult {
            $ran[] = 'general_knowledge';

            return new ToolResult(ToolResult::TERMINAL, terminalOutcome: new TurnOutcome(
                'answer', 'Explicación general de la figura, con la fuente citada.', [],
                ['floor_decision' => ['path' => 'general_knowledge', 'outcome' => 'answer', 'authority_used' => ['general_knowledge']]], null,
            ), plannerSummary: ['status' => 'answer']);
        });
    }

    private function plannerCall(string $tool, array $input = []): array
    {
        return ['calls' => [['id' => 't-'.$tool, 'tool' => $tool, 'input' => $input]]];
    }

    public function test_rule_opens_the_lane_after_an_entailment_only_failure(): void
    {
        $ran = [];
        $agent = $this->agentWith(
            new LanePreconditionScriptedPlanner([$this->plannerCall('convenio_search'), $this->plannerCall('general_knowledge')]),
            $this->corpusTool($this->outcome($this->entailmentFloor())),
            $this->laneTool($ran),
        );

        $response = $agent->handle($this->employee, '¿Qué es una excedencia?');

        $this->assertSame(['general_knowledge'], $ran, 'the lane tool ran');
        $this->assertFalse($response['escalated']);
        $denied = array_filter($response['trace']['agent']['steps'], fn ($s) => ($s['type'] ?? null) === 'tool_denied');
        $this->assertSame([], array_values($denied));
    }

    public function test_rule_still_opens_the_lane_after_a_check_a_miss_as_before(): void
    {
        $ran = [];
        $miss = $this->outcome(['check_a_retrieval' => false, 'outcome' => 'escalate', 'escalation_reason' => 'low_confidence']);
        $agent = $this->agentWith(
            new LanePreconditionScriptedPlanner([$this->plannerCall('convenio_search'), $this->plannerCall('general_knowledge')]),
            $this->corpusTool($miss),
            $this->laneTool($ran),
        );

        $agent->handle($this->employee, '¿Qué es una excedencia?');

        $this->assertSame(['general_knowledge'], $ran);
    }

    public function test_rule_denies_the_lane_when_the_stashed_outcome_is_a_figure_guard_verdict(): void
    {
        $ran = [];
        $figure = $this->outcome($this->entailmentFloor([
            'figure_grounding' => ['grounded' => false, 'ungrounded' => ['20 días']],
            'grounding' => ['checked' => false, 'grounded' => null, 'gate' => null, 'ungrounded' => null],
        ]));
        $agent = $this->agentWith(
            // Even if a (buggy or stale) tool labelled it NO_MATERIAL, the rule re-classifies and denies.
            new LanePreconditionScriptedPlanner([$this->plannerCall('convenio_search'), $this->plannerCall('general_knowledge'), $this->plannerCall('finalize', ['use' => []])]),
            $this->corpusTool($figure),
            $this->laneTool($ran),
        );

        $response = $agent->handle($this->employee, '¿Qué es una excedencia?');

        $this->assertSame([], $ran, 'the lane tool must never run after a figure-guard verdict');
        $denied = array_values(array_filter($response['trace']['agent']['steps'], fn ($s) => ($s['type'] ?? null) === 'tool_denied'));
        $this->assertCount(1, $denied);
        $this->assertSame('general_lane_availability', $denied[0]['reason']);
        $this->assertTrue($response['escalated']);
        $this->assertSame('low_confidence', $response['escalation_reason']);
    }

    public function test_rule_denies_not_force_blocks_an_entailment_failure_on_a_prescreen_question(): void
    {
        $ran = [];
        $agent = $this->agentWith(
            new LanePreconditionScriptedPlanner([$this->plannerCall('convenio_search'), $this->plannerCall('general_knowledge'), $this->plannerCall('finalize', ['use' => []])]),
            $this->corpusTool($this->outcome($this->entailmentFloor())),
            $this->laneTool($ran),
        );

        $response = $agent->handle($this->employee, '¿Cuántos días de excedencia me corresponden?');

        $this->assertSame([], $ran);
        $this->assertTrue($response['escalated']);
        // The corpus escalation stands (low_confidence) — NOT general_lane_blocked.
        $this->assertSame('low_confidence', $response['escalation_reason']);
    }

    public function test_rule_denies_the_lane_with_no_prior_corpus_miss_at_all(): void
    {
        $ran = [];
        $agent = $this->agentWith(
            new LanePreconditionScriptedPlanner([$this->plannerCall('general_knowledge'), $this->plannerCall('escalate', ['category' => 'unanswerable', 'reason' => 'sin material'])]),
            $this->laneTool($ran),
        );

        $agent->handle($this->employee, '¿Qué es una excedencia?');

        $this->assertSame([], $ran);
    }
}

final class LanePreconditionFakeTool implements Tool
{
    /** @param  \Closure():ToolResult  $run */
    public function __construct(private readonly string $toolName, private readonly \Closure $run) {}

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
        return false;
    }

    public function run(array $input, TurnState $state): ToolResult
    {
        return ($this->run)();
    }
}

final class LanePreconditionScriptedPlanner implements PlannerClient
{
    private int $index = 0;

    /** @param  list<array<string,mixed>>  $responses */
    public function __construct(private readonly array $responses) {}

    public function plan(string $question, array $scopeSummary, array $window, array $toolDefinitions, array $priorSteps): array
    {
        if (! isset($this->responses[$this->index])) {
            throw new PlannerUnavailableException('queue exhausted');
        }
        $response = $this->responses[$this->index++];

        return $response + ['stop_reason' => 'tool_use', 'model' => null, 'request_id' => null, 'prompt_version' => null, 'tokens' => [], 'ms' => 0];
    }
}
