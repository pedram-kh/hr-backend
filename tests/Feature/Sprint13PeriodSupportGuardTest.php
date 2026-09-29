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
 * Sprint 13, build step 5 (plan.md §F.15, user-directed follow-up) —
 * `Rules\PeriodSupportGuard` (`turn_start`): an explicit past year in the
 * question, with no supported way to honour it (`period` dropped from
 * `ask_employee`'s whitelist this pass), must escalate `low_confidence`/
 * `period_unsupported` — NEVER silently answer with the current year.
 *
 * Agent-only: classic has the identical underlying limitation (§F.15) but
 * is untouched here — `Sprint13GoldenTraceTest`'s 22 cases stay
 * byte-identical, confirmed separately after this change.
 */
class Sprint13PeriodSupportGuardTest extends TestCase
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

    private function employee(): Employee
    {
        $convenio = Convenio::create([
            'numero' => '13PSG-'.uniqid(), 'name' => 'Convenio periodo',
            'territory_id' => $this->territory->id, 'sector_id' => $this->sector->id,
        ]);

        return Employee::create([
            'email' => 'psg-'.$convenio->numero.'@example.com', 'full_name' => 'Period Guard Test',
            'convenio_id' => $convenio->id, 'territory_id' => $this->territory->id,
            'employment_type' => 'full_time', 'status' => 'active',
        ]);
    }

    /** An exploding planner — proves `/plan` is never called when the guard fires at `turn_start`. */
    private function explodingPlanner(): PlannerClient
    {
        return new class implements PlannerClient
        {
            public function plan(string $question, array $scopeSummary, array $window, array $toolDefinitions, array $priorSteps): array
            {
                throw new \RuntimeException('the planner must never be called — turn_start already terminated the turn');
            }
        };
    }

    public function test_explicit_past_year_on_a_fresh_salary_question_escalates_instead_of_short_circuiting(): void
    {
        $employee = $this->employee();
        $this->app->bind(PlannerClient::class, fn () => $this->explodingPlanner());

        // A plain first-turn salary question would normally short-circuit
        // through round 0 straight to `SalaryPath::handle()` (today's
        // year) — the guard must intercept it BEFORE that, not just before
        // the planner.
        $result = app(AgentChatService::class)->handle($employee, '¿Cuánto cobré en 2023?');

        $this->assertSame('escalate', $result['outcome']);
        $this->assertSame('low_confidence', $result['escalation_reason']);
        $this->assertSame(ChatService::EMPLOYEE_ESCALATION_MESSAGE, $result['answer']);
        $this->assertSame(2023, $result['trace']['agent']['period_unsupported']['matched_year']);
        $this->assertNotNull($result['escalation_uuid']);
    }

    public function test_explicit_past_year_on_a_follow_up_question_escalates_before_the_planner_loop(): void
    {
        $employee = $this->employee();
        $session = ChatSession::create(['employee_id' => $employee->id, 'started_at' => now(), 'last_activity_at' => now()]);
        $this->app->bind(PlannerClient::class, fn () => $this->explodingPlanner());

        $result = app(AgentChatService::class)->handle($employee, '¿Y en 2022 cuánto cobraba?', $session->uuid);

        $this->assertSame('escalate', $result['outcome']);
        $this->assertSame('low_confidence', $result['escalation_reason']);
        $this->assertSame(2022, $result['trace']['agent']['period_unsupported']['matched_year']);
    }

    public function test_current_year_mention_does_not_fire_the_guard(): void
    {
        $employee = $this->employee();
        $currentYear = now()->year;
        $this->app->bind(PlannerClient::class, fn () => $this->explodingPlanner());
        $session = ChatSession::create(['employee_id' => $employee->id, 'started_at' => now(), 'last_activity_at' => now()]);
        ChatMessage::create(['session_id' => $session->id, 'role' => 'user', 'content' => 'hola']);
        ChatMessage::create(['session_id' => $session->id, 'role' => 'assistant', 'content' => 'hola']);

        // Mentioning THIS year must not trip the guard — it isn't a past
        // period at all. The exploding planner throwing proves the guard
        // didn't force-terminate; some other real code path (round 0 /
        // the loop) must have run instead.
        try {
            app(AgentChatService::class)->handle($employee, "¿Cuánto cobraré en {$currentYear}?", $session->uuid);
            $this->fail('expected the exploding planner to run and throw — the guard must not have fired for the current year');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('the planner must never be called', $e->getMessage());
        }
    }

    public function test_no_year_mention_does_not_fire_the_guard(): void
    {
        $employee = $this->employee();
        $this->app->bind(PlannerClient::class, fn () => $this->explodingPlanner());
        $session = ChatSession::create(['employee_id' => $employee->id, 'started_at' => now(), 'last_activity_at' => now()]);
        ChatMessage::create(['session_id' => $session->id, 'role' => 'user', 'content' => 'hola']);
        ChatMessage::create(['session_id' => $session->id, 'role' => 'assistant', 'content' => 'hola']);

        try {
            app(AgentChatService::class)->handle($employee, '¿Cuánto voy a cobrar este mes?', $session->uuid);
            $this->fail('expected the exploding planner to run and throw');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('the planner must never be called', $e->getMessage());
        }
    }
}
