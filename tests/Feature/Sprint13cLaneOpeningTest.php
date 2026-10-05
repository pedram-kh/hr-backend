<?php

namespace Tests\Feature;

use App\Models\ChatSession;
use App\Models\Convenio;
use App\Models\Employee;
use App\Models\Sector;
use App\Models\Territory;
use App\Services\Agent\Rules\CorpusMiss;
use App\Services\Agent\Rules\GeneralLaneAvailabilityRule;
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
 * Slice 13c (plan.md §2.2): a synthesis ABSTENTION opens the lane — only with the model-knowledge sub-flag on, only for
 * the exact abstention shape, and only for questions the fail-closed v2 gate admits. Sub-flag off = Sprint 13, unchanged.
 */
class Sprint13cLaneOpeningTest extends TestCase
{
    use RefreshDatabase;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $territory = Territory::create(['code' => '01', 'name' => 'Álava', 'level' => 'provincial', 'aliases' => []]);
        $sector = Sector::create(['name' => 'Sector', 'aliases' => []]);
        $convenio = Convenio::create(['numero' => '13CLANE001', 'name' => '13c opening', 'territory_id' => $territory->id, 'sector_id' => $sector->id]);
        $this->employee = Employee::create([
            'email' => 'lane-13c@example.com', 'full_name' => 'Lane Trece',
            'convenio_id' => $convenio->id, 'territory_id' => $territory->id,
            'employment_type' => 'full_time', 'status' => 'active',
        ]);

        $this->flags(true, true);
    }

    private function flags(bool $lane, bool $modelKnowledge): void
    {
        config(['hr.general_lane.enabled' => $lane, 'hr.general_lane.model_knowledge' => $modelKnowledge]);
        GuardrailPolicy::flush();
    }

    /** @param  array<string,mixed>  $override */
    private function abstentionFloor(array $override = []): array
    {
        return array_replace_recursive([
            'retrieval_score_floor' => 0.4,
            'answer_confidence_floor' => 0.65,
            'check_a_retrieval' => true,
            'check_b_citations' => false,
            'check_c_confidence_tiebreaker' => ['confidence' => 0.1, 'below_floor' => true, 'used_as_gate' => false],
            'figure_grounding' => ['checked' => true, 'figures' => [], 'grounded' => true, 'ungrounded' => []],
            'grounding' => ['checked' => false, 'reason' => 'short-circuited before /ground'],
            'authority_used' => [],
            'outcome' => 'escalate',
            'escalation_reason' => 'low_confidence',
            'note' => 'no valid citations (Check B failed)',
        ], $override);
    }

    private function outcome(array $floor): TurnOutcome
    {
        return new TurnOutcome('escalate', ChatService::ESCALATION_MESSAGE, [], ['floor_decision' => $floor, 'retrieval' => []], 'low_confidence');
    }

    private function turnState(string $question): TurnState
    {
        $session = ChatSession::create(['employee_id' => $this->employee->id, 'started_at' => now(), 'last_activity_at' => now()]);

        return new TurnState($this->employee, $question, Carbon::today(), $session, []);
    }

    // ---- classify ------------------------------------------------------

    public function test_classify_recognises_the_abstention_only_when_allowed(): void
    {
        $o = $this->outcome($this->abstentionFloor());
        $this->assertNull(CorpusMiss::classify($o), 'default = the Sprint-13 whitelist, unchanged');
        $this->assertSame(CorpusMiss::SYNTHESIS_ABSTENTION, CorpusMiss::classify($o, true));
    }

    /** @return array<string,array{0:array<string,mixed>}> */
    public static function notAnAbstention(): array
    {
        return [
            'confident answer with invalid citations (hallucinated chunk ids)' => [['check_c_confidence_tiebreaker' => ['confidence' => 0.9]]],
            'confidence just above the abstention ceiling' => [['check_c_confidence_tiebreaker' => ['confidence' => 0.21]]],
            'provider error' => [['note' => 'provider error']],
            'check B passed (a different failure)' => [['check_b_citations' => true, 'note' => 'answer figure not grounded in cited chunk (figure-guard pre-check)']],
            'other escalation reason' => [['escalation_reason' => 'estatuto_fallback_gap']],
            'check A missed (a different shape; classified separately)' => [['check_a_retrieval' => false]],
        ];
    }

    /** @param  array<string,mixed>  $override */
    #[DataProvider('notAnAbstention')]
    public function test_classify_never_reads_another_shape_as_an_abstention(array $override): void
    {
        $this->assertNotSame(CorpusMiss::SYNTHESIS_ABSTENTION, CorpusMiss::classify($this->outcome($this->abstentionFloor($override)), true));
    }

    public function test_a_missing_confidence_is_not_an_abstention(): void
    {
        $floor = $this->abstentionFloor();
        unset($floor['check_c_confidence_tiebreaker']);
        $this->assertNull(CorpusMiss::classify($this->outcome($floor), true));
    }

    // ---- hand-over -----------------------------------------------------

    public function test_hand_over_kind_needs_the_lane_the_sub_flag_and_a_v2_admitted_question(): void
    {
        $policy = fn () => $this->app->make(GuardrailPolicy::class);
        $abst = $this->outcome($this->abstentionFloor());

        $this->assertSame(CorpusMiss::SYNTHESIS_ABSTENTION, CorpusMiss::handOverKind($abst, '¿Qué es la vida laboral?', $policy()));
        $this->assertTrue(CorpusMiss::laneMayTakeOver($abst, '¿Qué es la vida laboral?', $policy()));

        // v2 refuses what is not a definition-shaped question, and what v1 would miss
        foreach (['¿Cuántos días me corresponden?', '¿Qué es lo que me toca si me voy?', 'Quiero saber de la vida laboral', '¿Qué es el periodo de prueba en mi contrato?'] as $q) {
            $this->assertNull(CorpusMiss::handOverKind($abst, $q, $policy()), $q);
        }

        // sub-flag off: no abstention hand-over (Sprint-13 behaviour)
        $this->flags(true, false);
        $this->assertNull(CorpusMiss::handOverKind($abst, '¿Qué es la vida laboral?', $policy()));
        // lane off: nothing
        $this->flags(false, true);
        $this->assertNull(CorpusMiss::handOverKind($abst, '¿Qué es la vida laboral?', $policy()));
    }

    public function test_entailment_only_keeps_the_v1_gate_when_the_sub_flag_is_off_and_gains_v2_when_on(): void
    {
        $entail = $this->outcome([
            'check_a_retrieval' => true, 'check_b_citations' => true,
            'figure_grounding' => ['grounded' => true], 'outcome' => 'escalate', 'escalation_reason' => 'low_confidence',
            'grounding' => ['checked' => true, 'grounded' => false, 'ungrounded' => ['x'], 'error' => null, 'gate' => 'entailment', 'trace_fragment' => []],
        ]);
        // a question v1 lets through but v2 refuses (no definition shape)
        $q = 'Necesito información sobre la excedencia';

        $this->flags(true, false);
        $this->assertSame(CorpusMiss::ENTAILMENT_ONLY, CorpusMiss::handOverKind($entail, $q, $this->app->make(GuardrailPolicy::class)));
        $this->flags(true, true);
        $this->assertNull(CorpusMiss::handOverKind($entail, $q, $this->app->make(GuardrailPolicy::class)));
    }

    // ---- tools ---------------------------------------------------------

    /** @return array<string,array{0:class-string}> */
    public static function proseTools(): array
    {
        return ['convenio_search' => [ConvenioSearchTool::class], 'national_law' => [NationalLawTool::class]];
    }

    /** @param  class-string  $toolClass */
    #[DataProvider('proseTools')]
    public function test_prose_tools_hand_an_abstention_to_the_planner_as_abstained(string $toolClass): void
    {
        $outcome = $this->outcome($this->abstentionFloor());
        $this->mock(ProsePath::class, fn ($m) => $m->shouldReceive('handle')->once()->andReturn($outcome));

        $result = $this->app->make($toolClass)->run([], $this->turnState('¿Qué es la vida laboral?'));

        $this->assertSame(ToolResult::NO_MATERIAL, $result->status);
        $this->assertSame('abstained', $result->plannerSummary['status']);
        $this->assertSame($outcome, $result->terminalOutcome);
        $this->assertSame('synthesis_abstention', $result->traceBlocks['general_lane_precondition']['kind']);
        $this->assertSame(0.1, $result->traceBlocks['general_lane_precondition']['confidence']);
    }

    /** @param  class-string  $toolClass */
    #[DataProvider('proseTools')]
    public function test_prose_tools_stay_terminal_on_an_abstention_when_the_sub_flag_is_off_or_the_question_is_refused(string $toolClass): void
    {
        $outcome = $this->outcome($this->abstentionFloor());
        $this->mock(ProsePath::class, fn ($m) => $m->shouldReceive('handle')->twice()->andReturn($outcome));

        $this->flags(true, false);
        $off = $this->app->make($toolClass)->run([], $this->turnState('¿Qué es la vida laboral?'));
        $this->assertSame(ToolResult::TERMINAL, $off->status, 'sub-flag off: terminal exactly as in Sprint 13');
        $this->assertSame('escalate', $off->plannerSummary['status']);

        $this->flags(true, true);
        $refused = $this->app->make($toolClass)->run([], $this->turnState('¿Qué es lo que me corresponde por vida laboral?'));
        $this->assertSame(ToolResult::TERMINAL, $refused->status);
    }

    // ---- availability rule ---------------------------------------------

    private function withMaterial(TurnState $state, string $tool, ToolResult $r): TurnState
    {
        $state->material[$tool] = $r;

        return $state;
    }

    public function test_the_availability_rule_allows_after_an_abstention_and_denies_a_refused_question(): void
    {
        $rule = $this->app->make(GeneralLaneAvailabilityRule::class);
        $abst = new ToolResult(ToolResult::NO_MATERIAL, terminalOutcome: $this->outcome($this->abstentionFloor()), plannerSummary: ['status' => 'abstained']);

        $ok = $this->withMaterial($this->turnState('¿Qué es la vida laboral?'), 'convenio_search', $abst);
        $this->assertTrue($rule->evaluate($ok, ['tool' => 'general_knowledge', 'input' => []], null)->isAllow());

        // v2 refuses a first-person wrapper after an abstention: a DENY (the corpus escalation stands), not general_lane_blocked
        $bad = $this->withMaterial($this->turnState('¿Qué es la vida laboral en mi contrato?'), 'convenio_search', $abst);
        $v = $rule->evaluate($bad, ['tool' => 'general_knowledge', 'input' => []], null);
        $this->assertSame('deny', $v->status);

        // sub-flag off: an abstention does not open the lane
        $this->flags(true, false);
        $rule = $this->app->make(GeneralLaneAvailabilityRule::class);
        $off = $this->withMaterial($this->turnState('¿Qué es la vida laboral?'), 'convenio_search', $abst);
        $this->assertSame('deny', $rule->evaluate($off, ['tool' => 'general_knowledge', 'input' => []], null)->status);
    }

    public function test_a_check_a_miss_with_a_refused_question_is_forced_to_general_lane_blocked_and_records_the_v2_rule(): void
    {
        $rule = $this->app->make(GeneralLaneAvailabilityRule::class);
        $miss = new ToolResult(ToolResult::NO_MATERIAL, plannerSummary: ['status' => 'check_a_failed']);

        $state = $this->withMaterial($this->turnState('¿Cuánto me corresponde de excedencia?'), 'convenio_search', $miss);
        $v = $rule->evaluate($state, ['tool' => 'general_knowledge', 'input' => []], null);
        $this->assertTrue($v->isTerminal());
        $this->assertSame('general_lane_blocked', $v->forcePayload->escalationReason);
        $hits = $v->forcePayload->trace['general_lane']['postcheck']['hits'];
        $this->assertSame(['question_prescreen', 'question_gate_v2'], array_column($hits, 'pattern_id'));

        // sub-flag off: only the v1 record, as in Sprint 13
        $this->flags(true, false);
        $rule = $this->app->make(GeneralLaneAvailabilityRule::class);
        $v = $rule->evaluate($state, ['tool' => 'general_knowledge', 'input' => []], null);
        $this->assertSame(['question_prescreen'], array_column($v->forcePayload->trace['general_lane']['postcheck']['hits'], 'pattern_id'));
    }
}
