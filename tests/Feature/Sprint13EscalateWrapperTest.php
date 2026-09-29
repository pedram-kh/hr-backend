<?php

namespace Tests\Feature;

use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\Convenio;
use App\Models\Employee;
use App\Models\Sector;
use App\Models\Territory;
use App\Services\Agent\AgentChatService;
use App\Services\Agent\PlannerClient;
use App\Services\ChatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sprint 13, build step 5 (plan.md §B.5) — `escalate` needs no new
 * production code: `AgentChatService::processRound()` has handled a
 * planner-initiated `escalate` call since step 3 (the same-round precedence
 * over `finalize` was one of {@see Sprint13RuleEngineInvariantTest}'s
 * original 5 cases). This is the contract test the plan's own §B.5 text
 * describes: the category/reason shape, the employee-visible override, and
 * — now that a REAL tool exists whose post-call rule can force an
 * escalation in the SAME round — the precedence rule stated in prose
 * ("the rule's reason wins... the more specific, deterministic fact for
 * HR") proved with `salary_lookup`'s coverage gap rather than
 * `Sprint13RuleEngineInvariantTest`'s generic fake rule/tool stand-ins.
 */
class Sprint13EscalateWrapperTest extends TestCase
{
    use RefreshDatabase;

    private Territory $territory;

    private Sector $sector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->territory = Territory::create(['code' => '31', 'name' => 'Navarra', 'level' => 'provincial', 'aliases' => []]);
        $this->sector = Sector::create(['name' => 'Hostelería', 'aliases' => []]);
    }

    private function convenio(string $numero): Convenio
    {
        return Convenio::create([
            'numero' => '13ESC-'.$numero, 'name' => 'Convenio '.$numero,
            'territory_id' => $this->territory->id, 'sector_id' => $this->sector->id,
        ]);
    }

    private function employee(Convenio $convenio): Employee
    {
        return Employee::create([
            'email' => 'esc-'.$convenio->numero.'@example.com', 'full_name' => 'Escalate Wrapper Test',
            'convenio_id' => $convenio->id, 'territory_id' => $this->territory->id,
            'employment_type' => 'full_time', 'status' => 'active',
        ]);
    }

    private function followUpSession(Employee $employee): ChatSession
    {
        $session = ChatSession::create(['employee_id' => $employee->id, 'started_at' => now(), 'last_activity_at' => now()]);
        ChatMessage::create(['session_id' => $session->id, 'role' => 'user', 'content' => 'Hola, tengo una duda.']);
        ChatMessage::create(['session_id' => $session->id, 'role' => 'assistant', 'content' => 'Claro, dime.']);

        return $session;
    }

    public function test_planner_escalate_records_category_and_reason_and_overrides_the_employee_message(): void
    {
        $employee = $this->employee($this->convenio('plain'));
        $planner = new class implements PlannerClient
        {
            public function plan(string $question, array $scopeSummary, array $window, array $toolDefinitions, array $priorSteps): array
            {
                return ['calls' => [['id' => 't1', 'tool' => 'escalate', 'input' => ['category' => 'unanswerable', 'reason' => 'no tool covers this']]], 'stop_reason' => 'tool_use', 'model' => null, 'request_id' => null, 'prompt_version' => null, 'tokens' => [], 'ms' => 0];
            }
        };
        $this->app->bind(PlannerClient::class, fn () => $planner);
        $session = $this->followUpSession($employee);

        $result = app(AgentChatService::class)->handle($employee, '¿Puedes valorar mi situación personal?', $session->uuid);

        $this->assertSame('escalate', $result['outcome']);
        $this->assertSame('planner_escalated', $result['escalation_reason']);
        $this->assertSame(
            ChatService::EMPLOYEE_ESCALATION_MESSAGE,
            $result['answer'],
            'the one-place override (ADR-0029) must hold for the agent engine too',
        );
        $this->assertSame(['category' => 'unanswerable', 'reason' => 'no tool covers this'], $result['trace']['agent']['planner_escalation']);
        $this->assertNotNull($result['escalation_uuid']);
    }

    public function test_a_same_round_rule_forced_escalation_beats_the_planners_own_escalate_call(): void
    {
        $convenio = $this->convenio('precedence'); // deliberately no salary table → coverage gap
        $employee = $this->employee($convenio);
        $planner = new class implements PlannerClient
        {
            public function plan(string $question, array $scopeSummary, array $window, array $toolDefinitions, array $priorSteps): array
            {
                // BOTH in the same round — §B.5's precedence: the rule's
                // reason (salary_coverage_gap) must win over planner_escalated.
                return ['calls' => [
                    ['id' => 't1', 'tool' => 'salary_lookup', 'input' => []],
                    ['id' => 't2', 'tool' => 'escalate', 'input' => ['category' => 'other', 'reason' => 'not sure']],
                ], 'stop_reason' => 'tool_use', 'model' => null, 'request_id' => null, 'prompt_version' => null, 'tokens' => [], 'ms' => 0];
            }
        };
        $this->app->bind(PlannerClient::class, fn () => $planner);
        $session = $this->followUpSession($employee);

        $result = app(AgentChatService::class)->handle($employee, '¿Cuánto voy a cobrar este mes?', $session->uuid);

        $this->assertSame('escalate', $result['outcome']);
        $this->assertSame('salary_coverage_gap', $result['escalation_reason'], 'the tool-boundary rule verdict must win over the same-round planner escalate');
        $this->assertNull($result['trace']['agent']['planner_escalation'] ?? null);
    }
}
