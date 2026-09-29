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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sprint 13, build step 5 (plan.md §B.4) — `ask_employee` is genuinely NEW
 * behaviour (classic has no generic "ask the employee a clarifying
 * question" tool, only `salary_lookup`'s `needs_category` pick, covered by
 * `Sprint13SalaryLookupWrapperTest`), so there is no classic call site to
 * byte-compare against. This is a CONTRACT test instead: proves
 * `Rules\AskEmployeeWhitelist` (`pre_call:ask_employee`) enforces §B.4.1's
 * ordered checks, `Tools\AskEmployeeTool` builds exactly the `ask` outcome
 * shape §B.4.2 describes (no citations, no card, `floor_decision.outcome
 * = 'ask'`), and `Rules\AskEmployeePostCallRule` always forces it.
 */
class Sprint13AskEmployeeWrapperTest extends TestCase
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
            'numero' => '13AEW-'.$numero, 'name' => 'Convenio '.$numero,
            'territory_id' => $this->territory->id, 'sector_id' => $this->sector->id,
        ]);
    }

    private function employee(Convenio $convenio, ?int $convenioGroupId = null): Employee
    {
        return Employee::create([
            'email' => 'aew-'.$convenio->numero.'@example.com', 'full_name' => 'Ask Employee Wrapper Test',
            'convenio_id' => $convenio->id, 'convenio_group_id' => $convenioGroupId,
            'territory_id' => $this->territory->id, 'employment_type' => 'full_time', 'status' => 'active',
        ]);
    }

    private function followUpSession(Employee $employee): ChatSession
    {
        $session = ChatSession::create(['employee_id' => $employee->id, 'started_at' => now(), 'last_activity_at' => now()]);
        ChatMessage::create(['session_id' => $session->id, 'role' => 'user', 'content' => 'Hola, tengo una duda.']);
        ChatMessage::create(['session_id' => $session->id, 'role' => 'assistant', 'content' => 'Claro, dime.']);

        return $session;
    }

    /** A planner that yields a fixed sequence of `ask_employee` calls, then `escalate` if it runs out. */
    private function scriptedAskPlanner(array $inputs): PlannerClient
    {
        return new class($inputs) implements PlannerClient
        {
            private int $round = 0;

            public function __construct(private array $inputs) {}

            public function plan(string $question, array $scopeSummary, array $window, array $toolDefinitions, array $priorSteps): array
            {
                $round = $this->round++;
                if (isset($this->inputs[$round])) {
                    return ['calls' => [['id' => 't'.$round, 'tool' => 'ask_employee', 'input' => $this->inputs[$round]]], 'stop_reason' => 'tool_use', 'model' => null, 'request_id' => null, 'prompt_version' => null, 'tokens' => [], 'ms' => 0];
                }

                return ['calls' => [['id' => 'esc', 'tool' => 'escalate', 'input' => ['category' => 'other', 'reason' => 'ran out of script']]], 'stop_reason' => 'tool_use', 'model' => null, 'request_id' => null, 'prompt_version' => null, 'tokens' => [], 'ms' => 0];
            }
        };
    }

    private function runTurn(Employee $employee, string $question, array $askInputs): array
    {
        $this->app->bind(PlannerClient::class, fn () => $this->scriptedAskPlanner($askInputs));
        $session = $this->followUpSession($employee);

        return app(AgentChatService::class)->handle($employee, $question, $session->uuid);
    }

    public function test_allowed_topic_produces_a_plain_ask_outcome(): void
    {
        $employee = $this->employee($this->convenio('allowed'));

        $result = $this->runTurn($employee, '¿Puedo pedir excedencia y también preguntar por permisos?', [
            ['topic' => 'sub_question', 'question' => '¿Preguntas por la excedencia o por los permisos retribuidos?'],
        ]);

        $this->assertSame('ask', $result['outcome']);
        $this->assertFalse($result['escalated']);
        $this->assertNull($result['escalation_reason']);
        $this->assertSame('¿Preguntas por la excedencia o por los permisos retribuidos?', $result['answer']);
        $this->assertSame([], $result['citations']);
        $this->assertNull($result['escalation_uuid']);
        $this->assertSame('ask', $result['trace']['floor_decision']['outcome']);
    }

    public function test_topic_outside_whitelist_is_denied_and_the_planner_can_recover(): void
    {
        $employee = $this->employee($this->convenio('badtopic'));

        // Round 1: an out-of-whitelist topic (work_regime — dropped per §F.4)
        // is denied, NOT forced; round 2 recovers with an allowed topic.
        $result = $this->runTurn($employee, '¿Trabajo a tiempo completo o parcial?', [
            ['topic' => 'work_regime', 'question' => '¿Trabajas a tiempo completo?'],
            ['topic' => 'sub_question', 'question' => '¿Sobre qué periodo preguntas exactamente?'],
        ]);

        $this->assertSame('ask', $result['outcome']);
        $steps = $result['trace']['agent']['steps'];
        $denied = collect($steps)->first(fn ($s) => ($s['type'] ?? null) === 'tool_denied' && $s['tool'] === 'ask_employee');
        $this->assertNotNull($denied, 'the out-of-whitelist topic must have been denied, not forced');
        $this->assertSame('ask_employee_whitelist', $denied['reason']);
    }

    public function test_forbidden_field_on_empty_directory_value_forces_profile_incomplete(): void
    {
        // convenio_group_id left null → "professional_group" is EMPTY.
        $employee = $this->employee($this->convenio('emptygroup'), convenioGroupId: null);

        $result = $this->runTurn($employee, '¿Qué me corresponde según mi grupo?', [
            ['topic' => 'sub_question', 'question' => '¿A qué grupo profesional perteneces?'],
        ]);

        $this->assertSame('escalate', $result['outcome']);
        $this->assertSame('profile_incomplete', $result['escalation_reason']);
        $this->assertSame('professional_group', $result['trace']['agent']['profile_incomplete']['field']);
        $this->assertNotNull($result['escalation_uuid']);
    }

    public function test_forbidden_field_on_present_directory_value_forces_asserted_differs(): void
    {
        // convenio_id is NEVER null — always the "present" branch (§F.7).
        $employee = $this->employee($this->convenio('present'));

        $result = $this->runTurn($employee, 'Si mi convenio fuera otro, ¿cambiaría esto?', [
            ['topic' => 'sub_question', 'question' => '¿Cuál es tu convenio?'],
        ]);

        $this->assertSame('escalate', $result['outcome']);
        $this->assertSame('profile_incomplete', $result['escalation_reason']);
        $this->assertSame('asserted_differs', $result['trace']['agent']['profile_incomplete']['field']);
    }

    public function test_salary_received_forbidden_field_is_denied_not_escalated(): void
    {
        $employee = $this->employee($this->convenio('salaryask'));

        $result = $this->runTurn($employee, '¿Es correcto lo que cobro?', [
            ['topic' => 'sub_question', 'question' => '¿Cuánto cobras exactamente?'],
            ['topic' => 'sub_question', 'question' => '¿Sobre qué periodo preguntas?'],
        ]);

        // A deny (not a force) — the SECOND scripted ask (a legitimate one)
        // must have gone through, proving the first was denied, not forced.
        $this->assertSame('ask', $result['outcome']);
        $this->assertSame('¿Sobre qué periodo preguntas?', $result['answer']);
        $steps = $result['trace']['agent']['steps'];
        $denied = collect($steps)->first(fn ($s) => ($s['type'] ?? null) === 'tool_denied' && $s['tool'] === 'ask_employee');
        $this->assertNotNull($denied);
    }

    public function test_question_too_long_is_denied(): void
    {
        $employee = $this->employee($this->convenio('toolong'));
        $longQuestion = str_repeat('¿de verdad quieres saber esto? ', 10); // > 200 chars

        $result = $this->runTurn($employee, '¿Una pregunta cualquiera?', [
            ['topic' => 'sub_question', 'question' => $longQuestion],
            ['topic' => 'sub_question', 'question' => '¿Pregunta corta?'],
        ]);

        $this->assertSame('ask', $result['outcome']);
        $this->assertSame('¿Pregunta corta?', $result['answer']);
    }

    public function test_two_asks_then_third_is_budget_blocked(): void
    {
        $employee = $this->employee($this->convenio('budget'));
        $session = $this->followUpSession($employee);

        // Seed 2 prior ask turns in THIS session (counted by
        // `AgentChatService::countPriorClarifications()`).
        for ($i = 0; $i < 2; $i++) {
            $msg = ChatMessage::create(['session_id' => $session->id, 'role' => 'assistant', 'content' => '¿pregunta previa?']);
            MessageTrace::create(['message_id' => $msg->id, 'trace' => ['floor_decision' => ['outcome' => 'ask']]]);
        }

        $this->app->bind(PlannerClient::class, fn () => $this->scriptedAskPlanner([
            ['topic' => 'sub_question', 'question' => '¿una tercera pregunta?'],
        ]));

        $result = app(AgentChatService::class)->handle($employee, '¿Otra pregunta más?', $session->uuid);

        $this->assertSame('escalate', $result['outcome']);
        $this->assertSame('tool_budget_exhausted', $result['escalation_reason']);
        $this->assertSame('clarifications', $result['trace']['agent']['budget_exhausted']['sub']);
    }
}
