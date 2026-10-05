<?php

namespace Tests\Feature;

use App\Models\ChatSession;
use App\Models\Convenio;
use App\Models\Document;
use App\Models\DocumentType;
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
use App\Services\Agent\Rules\ReferenceFactPostCallRule;
use App\Services\Agent\Tool;
use App\Services\Agent\ToolRegistry;
use App\Services\Agent\ToolResult;
use App\Services\Agent\Tools\ConvenioSearchTool;
use App\Services\Agent\Tools\NationalLawTool;
use App\Services\Agent\Tools\ReferenceFactTool;
use App\Services\Agent\TurnState;
use App\Services\Answer\ProsePath;
use App\Services\Answer\ReferenceFactPath;
use App\Services\Answer\TurnOutcome;
use App\Services\ChatService;
use App\Services\GuardrailPolicy;
use App\Services\ReferenceFactRouter;
use App\Support\EscalationExplainer;
use Database\Seeders\DocumentTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Correction-13c-01 (ADR-0038 amendment): "the lane is a property of the question, not of the account."
 *
 * The general-lane hand-over depends ONLY on (a) the question passing the explanatory pre-screen and (b) the corpus not
 * having answered. One test group per removed account-state exclusion — never ingested (the `fallback` marker), expired,
 * scope under review, no chunks (`estatuto_fallback_gap`'s three flavours, through the REAL `ProsePath`/coverage service),
 * and no group (`reference_fact_coverage_gap`): an explanatory question reaches the lane; an entitlement question keeps
 * exactly the escalation it has today and the lane is absent; lane off is byte-identical.
 */
class Correction13c01LaneIsAPropertyOfTheQuestionTest extends TestCase
{
    use RefreshDatabase;

    private const EXPLANATORY = '¿Qué es un permiso PIF?';

    private const ENTITLEMENT = '¿Cuántos días de permiso me corresponden?';

    private Territory $territory;

    private Sector $sector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DocumentTypeSeeder::class);
        $this->territory = Territory::create(['code' => '31', 'name' => 'Navarra', 'level' => 'provincial', 'aliases' => []]);
        $this->sector = Sector::create(['name' => 'Sector', 'aliases' => []]);
        $this->flags(true, true);
    }

    private function flags(bool $lane, bool $modelKnowledge = true): void
    {
        config(['hr.general_lane.enabled' => $lane, 'hr.general_lane.model_knowledge' => $modelKnowledge]);
        GuardrailPolicy::flush();
    }

    private function employee(string $tag): Employee
    {
        $convenio = Convenio::create([
            'numero' => '13C01-'.$tag, 'name' => 'Convenio '.$tag,
            'territory_id' => $this->territory->id, 'sector_id' => $this->sector->id,
        ]);

        return Employee::create([
            'email' => 'c13c01-'.$tag.'@example.com', 'full_name' => 'Correction 13c01 '.$tag,
            'convenio_id' => $convenio->id, 'territory_id' => $this->territory->id,
            'employment_type' => 'full_time', 'status' => 'active',
        ]);
    }

    /** A prose document of the employee's convenio in the given state. */
    private function proseDoc(Employee $employee, string $retrievalStatus, string $taggingStatus): Document
    {
        return Document::create([
            'uuid' => (string) Str::uuid(), 'title' => 'Convenio texto', 'storage_path' => 'fake/'.Str::uuid(),
            'convenio_id' => $employee->convenio_id,
            'document_type_id' => DocumentType::where('code', 'convenio_text')->value('id'),
            'authority_level' => 'official_convenio', 'retrieval_status' => $retrievalStatus,
            'language' => 'es', 'tagging_status' => $taggingStatus,
        ]);
    }

    private function turnState(Employee $employee, string $question): TurnState
    {
        $session = ChatSession::create(['employee_id' => $employee->id, 'started_at' => now(), 'last_activity_at' => now()]);

        return new TurnState($employee, $question, Carbon::today(), $session, []);
    }

    // =====================================================================
    // 1. Never ingested — the `fallback` marker (ProsePath's `estatuto_gap`)
    // =====================================================================

    /** @param  array<string,mixed>  $override */
    private function neverIngestedAbstention(array $override = []): TurnOutcome
    {
        $floor = array_replace_recursive([
            'retrieval_score_floor' => 0.4, 'answer_confidence_floor' => 0.65,
            'check_a_retrieval' => true, 'check_b_citations' => true,
            'check_c_confidence_tiebreaker' => ['confidence' => 0.1, 'below_floor' => true, 'used_as_gate' => false],
            'figure_grounding' => ['checked' => true, 'figures' => [], 'grounded' => true, 'ungrounded' => []],
            'grounding' => ['checked' => false, 'reason' => 'short-circuited before /ground'],
            'authority_used' => [], 'outcome' => 'escalate', 'escalation_reason' => 'low_confidence',
            'note' => 'synthesis abstained (flag)', 'synthesis_abstained' => ['flag' => true, 'by' => 'model_flag'],
            'fallback' => ChatService::FALLBACK_ESTATUTO_GAP,
        ], $override);

        return new TurnOutcome('escalate', ChatService::ESCALATION_MESSAGE, [], ['floor_decision' => $floor, 'retrieval' => [], 'prose_gap' => ['classification' => 'never_ingested', 'reason_code' => null]], 'low_confidence');
    }

    private function neverIngestedEntailment(): TurnOutcome
    {
        $floor = [
            'retrieval_score_floor' => 0.4, 'answer_confidence_floor' => 0.65,
            'check_a_retrieval' => true, 'check_b_citations' => true,
            'check_c_confidence_tiebreaker' => ['confidence' => 0.85, 'below_floor' => false, 'used_as_gate' => false],
            'figure_grounding' => ['checked' => true, 'figures' => [], 'grounded' => true, 'ungrounded' => []],
            'grounding' => ['checked' => true, 'grounded' => false, 'claims' => [], 'ungrounded' => ['Un permiso PIF es un permiso.'], 'error' => null, 'gate' => 'entailment', 'trace_fragment' => []],
            'authority_used' => ['national_law'], 'outcome' => 'escalate', 'escalation_reason' => 'low_confidence',
            'note' => 'ungrounded claim (per-claim entailment gate)', 'fallback' => ChatService::FALLBACK_ESTATUTO_GAP,
        ];

        return new TurnOutcome('escalate', ChatService::ESCALATION_MESSAGE, [], ['floor_decision' => $floor, 'retrieval' => []], 'low_confidence');
    }

    /** @return array<string,array{0:class-string}> */
    public static function proseTools(): array
    {
        return ['convenio_search' => [ConvenioSearchTool::class], 'national_law' => [NationalLawTool::class]];
    }

    /** @param  class-string  $toolClass */
    #[DataProvider('proseTools')]
    public function test_never_ingested_an_explanatory_question_reaches_the_lane_on_an_abstention_and_on_an_entailment_failure(string $toolClass): void
    {
        $employee = $this->employee('never');

        $abst = $this->neverIngestedAbstention();
        $this->mock(ProsePath::class, fn ($m) => $m->shouldReceive('handle')->once()->andReturn($abst));
        $r = $this->app->make($toolClass)->run([], $this->turnState($employee, self::EXPLANATORY));
        $this->assertSame(ToolResult::NO_MATERIAL, $r->status);
        $this->assertSame('abstained', $r->plannerSummary['status']);
        $this->assertSame($abst, $r->terminalOutcome, 'the corpus escalation is stashed for the finisher');

        $ent = $this->neverIngestedEntailment();
        $this->mock(ProsePath::class, fn ($m) => $m->shouldReceive('handle')->once()->andReturn($ent));
        $r = $this->app->make($toolClass)->run([], $this->turnState($employee, self::EXPLANATORY));
        $this->assertSame(ToolResult::NO_MATERIAL, $r->status);
        $this->assertSame('entailment_failed', $r->plannerSummary['status']);
    }

    /** @param  class-string  $toolClass */
    #[DataProvider('proseTools')]
    public function test_never_ingested_an_entitlement_question_keeps_its_low_confidence_escalation_and_the_lane_stays_absent(string $toolClass): void
    {
        $employee = $this->employee('never-ent');

        foreach ([$this->neverIngestedAbstention(), $this->neverIngestedEntailment()] as $outcome) {
            $this->mock(ProsePath::class, fn ($m) => $m->shouldReceive('handle')->once()->andReturn($outcome));
            $r = $this->app->make($toolClass)->run([], $this->turnState($employee, self::ENTITLEMENT));
            $this->assertSame(ToolResult::TERMINAL, $r->status);
            $this->assertSame('low_confidence', $r->plannerSummary['escalation_reason']);
            $this->assertSame($outcome, $r->terminalOutcome);
        }
    }

    // =====================================================================
    // 2–4. Expired / scope under review / no chunks — `estatuto_fallback_gap`,
    //      through the REAL ProsePath + CorpusCoverageService
    // =====================================================================

    /** @return array<string,array{0:string,1:string,2:string}> state => [retrieval_status, tagging_status, expected explainer sub-outcome] */
    public static function fallbackGapStates(): array
    {
        return [
            'expired (historical text only)' => ['historical', 'verified', 'expired_no_successor'],
            'scope under review (tagging not verified)' => ['active', 'under_review', 'tagging_under_review'],
            'no chunks (active, verified, not embedded yet)' => ['active', 'verified', 'not_yet_embedded'],
        ];
    }

    private function employeeInGapState(string $tag, string $retrievalStatus, string $taggingStatus): Employee
    {
        $employee = $this->employee($tag);
        $this->proseDoc($employee, $retrievalStatus, $taggingStatus);

        return $employee;
    }

    #[DataProvider('fallbackGapStates')]
    public function test_gap_state_precondition_today_the_prose_path_escalates_estatuto_fallback_gap(string $retrieval, string $tagging, string $sub): void
    {
        // The unchanged corpus verdict this whole group stands on (real ProsePath, no AI call is spent).
        $employee = $this->employeeInGapState('pp-'.$sub, $retrieval, $tagging);
        $outcome = $this->app->make(ProsePath::class)->handle($employee, self::EXPLANATORY, [], Carbon::today(), null, []);

        $this->assertSame('escalate', $outcome->outcome);
        $this->assertSame('estatuto_fallback_gap', $outcome->escalationReason);
        $this->assertSame('expired_only', $outcome->trace['prose_gap']['classification']);
        $this->assertSame($sub, EscalationExplainer::subOutcomeOf('estatuto_fallback_gap', $outcome->trace));
        $this->assertSame(CorpusMiss::FALLBACK_GAP, CorpusMiss::classify($outcome));
    }

    #[DataProvider('fallbackGapStates')]
    public function test_gap_state_an_explanatory_question_reaches_the_lane(string $retrieval, string $tagging, string $sub): void
    {
        foreach ([ConvenioSearchTool::class, NationalLawTool::class] as $toolClass) {
            $employee = $this->employeeInGapState('open-'.$sub.'-'.class_basename($toolClass), $retrieval, $tagging);
            $r = $this->app->make($toolClass)->run([], $this->turnState($employee, self::EXPLANATORY));

            $this->assertSame(ToolResult::NO_MATERIAL, $r->status, class_basename($toolClass));
            $this->assertSame('check_a_failed', $r->plannerSummary['status']);
            $this->assertSame('expired_only', $r->plannerSummary['gap_class']);
            $this->assertSame('estatuto_fallback_gap', $r->terminalOutcome->escalationReason, 'the corpus escalation is stashed for the finisher');
            $this->assertSame(CorpusMiss::FALLBACK_GAP, $r->traceBlocks['general_lane_precondition']['kind']);
            $this->assertSame('expired_only', $r->traceBlocks['general_lane_precondition']['prose_gap']['classification']);
        }
    }

    #[DataProvider('fallbackGapStates')]
    public function test_gap_state_an_entitlement_question_keeps_estatuto_fallback_gap_and_the_lane_is_absent(string $retrieval, string $tagging, string $sub): void
    {
        foreach ([ConvenioSearchTool::class, NationalLawTool::class] as $toolClass) {
            $employee = $this->employeeInGapState('ent-'.$sub.'-'.class_basename($toolClass), $retrieval, $tagging);
            $r = $this->app->make($toolClass)->run([], $this->turnState($employee, self::ENTITLEMENT));

            $this->assertSame(ToolResult::TERMINAL, $r->status);
            $this->assertSame('escalate', $r->plannerSummary['status']);
            $this->assertSame('estatuto_fallback_gap', $r->plannerSummary['escalation_reason']);
            $this->assertArrayNotHasKey('general_lane_precondition', $r->traceBlocks);
        }
    }

    #[DataProvider('fallbackGapStates')]
    public function test_gap_state_lane_off_is_byte_identical(string $retrieval, string $tagging, string $sub): void
    {
        $employee = $this->employeeInGapState('off-'.$sub, $retrieval, $tagging);

        $direct = $this->app->make(ProsePath::class)->handle($employee, self::EXPLANATORY, [], Carbon::today(), null, []);

        $this->flags(false, false);
        $r = $this->app->make(ConvenioSearchTool::class)->run([], $this->turnState($employee, self::EXPLANATORY));

        $this->assertSame(ToolResult::TERMINAL, $r->status);
        $this->assertSame(['status' => 'escalate', 'escalation_reason' => 'estatuto_fallback_gap'], $r->plannerSummary);
        $this->assertSame([], $r->traceBlocks);
        $this->assertEquals($direct, $r->terminalOutcome, 'the very outcome the prose path produced, untouched');
    }

    public function test_the_expired_state_end_to_end_through_the_loop_ends_in_the_lane_for_an_explanatory_question_and_in_the_card_for_an_entitlement_one(): void
    {
        $employee = $this->employeeInGapState('e2e', 'historical', 'verified');

        $ran = [];
        $agent = $this->agent(new C13c01Planner([$this->plannerCall('convenio_search'), $this->plannerCall('general_knowledge')]), $this->laneTool($ran));
        $response = $agent->handle($employee, self::EXPLANATORY);
        $this->assertSame(['general_knowledge'], $ran, 'explanatory → the lane tool ran');
        $this->assertFalse($response['escalated']);
        $this->assertSame('general_knowledge', $response['trace']['floor_decision']['path']);

        // Entitlement: convenio_search is terminal, the planner never gets to ask for the lane.
        $ran = [];
        $employee2 = $this->employeeInGapState('e2e-ent', 'historical', 'verified');
        $agent = $this->agent(new C13c01Planner([$this->plannerCall('convenio_search'), $this->plannerCall('general_knowledge')]), $this->laneTool($ran));
        $response = $agent->handle($employee2, self::ENTITLEMENT);
        $this->assertSame([], $ran, 'entitlement → the lane tool never ran');
        $this->assertTrue($response['escalated']);
        $this->assertSame('estatuto_fallback_gap', $response['escalation_reason']);
    }

    public function test_when_the_lane_does_not_answer_the_finisher_resurfaces_the_same_corpus_escalation(): void
    {
        $employee = $this->employeeInGapState('finisher', 'historical', 'verified');
        $agent = $this->agent(new C13c01Planner([$this->plannerCall('convenio_search'), $this->plannerCall('finalize', ['use' => []])]), $this->laneTool($ran));
        $response = $agent->handle($employee, self::EXPLANATORY);

        $this->assertTrue($response['escalated']);
        $this->assertSame('estatuto_fallback_gap', $response['escalation_reason']);
    }

    // =====================================================================
    // 5. No group — `reference_fact_coverage_gap`
    // =====================================================================

    private function referenceFactGap(string $groupState, string $note): TurnOutcome
    {
        return new TurnOutcome('escalate', 'No puedo confirmarlo.', [], [
            'reference_fact' => ['note' => $note, 'employee_group_state' => $groupState],
            'floor_decision' => ['path' => 'reference_fact', 'outcome' => 'escalate', 'escalation_reason' => 'reference_fact_coverage_gap', 'authority_used' => [], 'note' => $note],
        ], 'reference_fact_coverage_gap');
    }

    private function groupUnknownGap(): TurnOutcome
    {
        return $this->referenceFactGap('unresolved', 'only per-group/per-category facts exist; employee scope does not confidently match one (group unresolved or different group) — never guess');
    }

    private function bindReferenceFact(TurnOutcome $outcome): void
    {
        $this->mock(ReferenceFactRouter::class, fn ($m) => $m->shouldReceive('detectTopic')->andReturn(['topic_id' => 1, 'topic_name' => 'Permisos', 'matched_topic_names' => ['Permisos']]));
        $this->mock(ReferenceFactPath::class, fn ($m) => $m->shouldReceive('handle')->atLeast()->once()->andReturn($outcome));
    }

    public function test_no_group_an_explanatory_question_is_handed_back_to_the_planner(): void
    {
        $employee = $this->employee('nogroup');
        $gap = $this->groupUnknownGap();
        $this->bindReferenceFact($gap);

        $r = $this->app->make(ReferenceFactTool::class)->run([], $this->turnState($employee, self::EXPLANATORY));

        $this->assertSame(ToolResult::NO_MATERIAL, $r->status);
        $this->assertSame(['status' => 'no_fact'], $r->plannerSummary, 'the planner already continues from no_fact with convenio_search');
        $this->assertSame($gap, $r->terminalOutcome, 'the gap escalation is stashed for the finisher');
        $this->assertSame('employee_group_unknown', $r->traceBlocks['general_lane_precondition']['sub_outcome']);
        $this->assertSame('reference_fact_gap', $r->traceBlocks['general_lane_precondition']['kind']);
    }

    public function test_no_group_an_entitlement_question_keeps_reference_fact_coverage_gap_and_the_lane_is_absent(): void
    {
        $employee = $this->employee('nogroup-ent');
        $gap = $this->groupUnknownGap();
        $this->bindReferenceFact($gap);

        $r = $this->app->make(ReferenceFactTool::class)->run([], $this->turnState($employee, self::ENTITLEMENT));

        $this->assertSame(ToolResult::TERMINAL, $r->status);
        $this->assertSame($gap, $r->terminalOutcome);
        $this->assertSame(['status' => 'escalate', 'topic' => 'Permisos'], $r->plannerSummary);
        $this->assertArrayNotHasKey('general_lane_precondition', $r->traceBlocks);
    }

    public function test_no_group_lane_off_and_a_fact_conflict_stay_terminal(): void
    {
        $employee = $this->employee('nogroup-off');
        $gap = $this->groupUnknownGap();
        $this->bindReferenceFact($gap);
        $this->flags(false, false);
        $r = $this->app->make(ReferenceFactTool::class)->run([], $this->turnState($employee, self::EXPLANATORY));
        $this->assertSame(ToolResult::TERMINAL, $r->status, 'lane off: byte-identical');
        $this->assertSame($gap, $r->terminalOutcome);

        // A same-validity conflict is a conflict verdict, never an absence: it never opens the lane.
        $this->flags(true, true);
        $conflict = $this->referenceFactGap('resolved', 'same-validity conflict: two verified facts disagree');
        $this->bindReferenceFact($conflict);
        $r = $this->app->make(ReferenceFactTool::class)->run([], $this->turnState($employee, self::EXPLANATORY));
        $this->assertSame(ToolResult::TERMINAL, $r->status);
    }

    public function test_no_group_round_zero_no_longer_settles_the_turn_for_an_explanatory_question_but_still_does_for_the_others(): void
    {
        $employee = $this->employee('nogroup-r0');
        $gap = $this->groupUnknownGap();
        $this->bindReferenceFact($gap);

        // Explanatory + lane on: round 0 defers; the planner runs reference_fact → (no material) → finalize re-surfaces the escalation.
        $planner = new C13c01Planner([$this->plannerCall('reference_fact'), $this->plannerCall('finalize', ['use' => []])]);
        $ran = [];
        $response = $this->agent($planner, $this->laneTool($ran))->handle($employee, self::EXPLANATORY);
        $this->assertSame(2, $planner->calls, 'the planner was consulted — round 0 did not settle the turn');
        $this->assertTrue($response['escalated']);
        $this->assertSame('reference_fact_coverage_gap', $response['escalation_reason']);

        // Entitlement: round 0 settles with the gap escalation, no planner call at all (today's behaviour).
        $planner = new C13c01Planner([]);
        // (a fresh employee: round 0 only settles a FIRST turn)
        $response = $this->agent($planner, $this->laneTool($ran))->handle($this->employee('nogroup-r0-ent'), self::ENTITLEMENT);
        $this->assertSame(0, $planner->calls);
        $this->assertSame('reference_fact_coverage_gap', $response['escalation_reason']);
    }

    // =====================================================================
    // 6. Verified fact + governing prose composition that did not answer (`reference_fact_composition`, `low_confidence`)
    // =====================================================================

    /** @param  array<string,mixed>  $composition */
    private function compositionLowConfidence(array $composition = [], bool $checkB = false, ?bool $grounded = null, string $reason = 'low_confidence'): TurnOutcome
    {
        $floor = ['path' => 'reference_fact_composition', 'check_b_citations' => $checkB, 'outcome' => 'escalate', 'escalation_reason' => $reason, 'note' => 'x'];
        if ($grounded !== null) {
            $floor['grounding'] = ['checked' => true, 'grounded' => $grounded, 'claims' => [], 'ungrounded' => []];
        }

        return new TurnOutcome('escalate', 'No puedo confirmarlo.', [], [
            'composition' => ['detected' => true, 'check_a' => true] + $composition,
            'floor_decision' => $floor,
        ], $reason);
    }

    public function test_composition_low_confidence_an_explanatory_question_is_handed_back_to_the_planner(): void
    {
        $employee = $this->employee('comp');
        $failed = $this->compositionLowConfidence();
        $this->bindReferenceFact($failed);

        $r = $this->app->make(ReferenceFactTool::class)->run([], $this->turnState($employee, self::EXPLANATORY));

        $this->assertSame(ToolResult::NO_MATERIAL, $r->status);
        $this->assertSame(['status' => 'no_fact'], $r->plannerSummary);
        $this->assertSame($failed, $r->terminalOutcome, 'the composition escalation is stashed for the finisher');
        $this->assertSame('reference_fact_composition', $r->traceBlocks['general_lane_precondition']['kind']);
        $this->assertFalse($r->traceBlocks['general_lane_precondition']['check_b_citations']);

        // An entailment failure of the composed claims is the same shape.
        $ungrounded = $this->compositionLowConfidence(checkB: true, grounded: false);
        $this->bindReferenceFact($ungrounded);
        $r = $this->app->make(ReferenceFactTool::class)->run([], $this->turnState($employee, self::EXPLANATORY));
        $this->assertSame(ToolResult::NO_MATERIAL, $r->status);
        $this->assertFalse($r->traceBlocks['general_lane_precondition']['grounded']);
    }

    public function test_composition_low_confidence_entitlement_lane_off_provider_error_and_conflict_stay_terminal(): void
    {
        $employee = $this->employee('comp-term');
        $failed = $this->compositionLowConfidence();

        $this->bindReferenceFact($failed);
        $r = $this->app->make(ReferenceFactTool::class)->run([], $this->turnState($employee, self::ENTITLEMENT));
        $this->assertSame(ToolResult::TERMINAL, $r->status, 'an entitlement question keeps today\'s escalation');
        $this->assertSame($failed, $r->terminalOutcome);
        $this->assertSame(['status' => 'escalate', 'topic' => 'Permisos'], $r->plannerSummary);
        $this->assertArrayNotHasKey('general_lane_precondition', $r->traceBlocks);

        $this->flags(false, false);
        $r = $this->app->make(ReferenceFactTool::class)->run([], $this->turnState($employee, self::EXPLANATORY));
        $this->assertSame(ToolResult::TERMINAL, $r->status, 'lane off: byte-identical');
        $this->flags(true, true);

        $providerError = $this->compositionLowConfidence(['synthesis_error' => 'timeout']);
        $this->bindReferenceFact($providerError);
        $r = $this->app->make(ReferenceFactTool::class)->run([], $this->turnState($employee, self::EXPLANATORY));
        $this->assertSame(ToolResult::TERMINAL, $r->status, 'a provider outage is not a corpus miss');

        $conflict = $this->compositionLowConfidence(reason: 'conflict');
        $this->bindReferenceFact($conflict);
        $r = $this->app->make(ReferenceFactTool::class)->run([], $this->turnState($employee, self::EXPLANATORY));
        $this->assertSame(ToolResult::TERMINAL, $r->status, 'a fact-vs-convenio conflict is a verdict');
    }

    public function test_composition_low_confidence_round_zero_defers_an_explanatory_first_turn_and_still_settles_the_others(): void
    {
        $failed = $this->compositionLowConfidence();
        $this->bindReferenceFact($failed);

        $planner = new C13c01Planner([$this->plannerCall('reference_fact'), $this->plannerCall('finalize', ['use' => []])]);
        $ran = [];
        $response = $this->agent($planner, $this->laneTool($ran))->handle($this->employee('comp-r0'), self::EXPLANATORY);
        $this->assertSame(2, $planner->calls, 'round 0 did not settle the turn');
        $this->assertTrue($response['escalated']);
        $this->assertSame('low_confidence', $response['escalation_reason'], 'nothing answered: the same escalation as today');

        $planner = new C13c01Planner([]);
        $response = $this->agent($planner, $this->laneTool($ran))->handle($this->employee('comp-r0-ent'), self::ENTITLEMENT);
        $this->assertSame(0, $planner->calls, 'entitlement: round 0 settles, as today');
        $this->assertSame('low_confidence', $response['escalation_reason']);
    }

    // =====================================================================
    // harness
    // =====================================================================

    /** @param  list<string>  $ran */
    private function laneTool(?array &$ran): Tool
    {
        $ran = [];

        return new C13c01Tool('general_knowledge', function () use (&$ran): ToolResult {
            $ran[] = 'general_knowledge';

            return new ToolResult(ToolResult::TERMINAL, terminalOutcome: new TurnOutcome(
                'answer', 'Un permiso es una ausencia autorizada del trabajo.', [],
                ['floor_decision' => ['path' => 'general_knowledge', 'outcome' => 'answer', 'authority_used' => ['general_knowledge']]], null,
            ), plannerSummary: ['status' => 'answer']);
        });
    }

    private function agent(PlannerClient $planner, Tool $lane): AgentChatService
    {
        $this->app->singleton(ToolRegistry::class, function ($app) use ($lane) {
            $registry = new ToolRegistry;
            $registry->register($app->make(ReferenceFactTool::class));
            $registry->register($app->make(ConvenioSearchTool::class));
            $registry->register($lane);

            return $registry;
        });
        $this->app->singleton(RuleEngine::class, function ($app) {
            $engine = new RuleEngine;
            $engine->register('pre_call', $app->make(ClarificationBudgetRule::class));
            $engine->register('post_call:reference_fact', $app->make(ReferenceFactPostCallRule::class));
            $engine->register('post_call:convenio_search', $app->make(ProseCheckAPostCallRule::class));
            $engine->register('pre_call:general_knowledge', $app->make(GeneralLaneAvailabilityRule::class));
            $engine->register('post_call:general_knowledge', $app->make(GeneralLaneFinishRule::class));

            return $engine;
        });
        $this->app->bind(PlannerClient::class, fn () => $planner);

        return $this->app->make(AgentChatService::class);
    }

    /** @return array{calls:list<array<string,mixed>>} */
    private function plannerCall(string $tool, array $input = []): array
    {
        return ['calls' => [['id' => 't-'.$tool, 'tool' => $tool, 'input' => $input]]];
    }
}

final class C13c01Tool implements Tool
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

final class C13c01Planner implements PlannerClient
{
    public int $calls = 0;

    private int $index = 0;

    /** @param  list<array<string,mixed>>  $responses */
    public function __construct(private readonly array $responses) {}

    public function plan(string $question, array $scopeSummary, array $window, array $toolDefinitions, array $priorSteps): array
    {
        $this->calls++;
        if (! isset($this->responses[$this->index])) {
            throw new PlannerUnavailableException('queue exhausted');
        }
        $response = $this->responses[$this->index++];

        return $response + ['stop_reason' => 'tool_use', 'model' => null, 'request_id' => null, 'prompt_version' => null, 'tokens' => [], 'ms' => 0];
    }
}
