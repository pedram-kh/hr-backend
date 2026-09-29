<?php

namespace Tests\Feature;

use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\Convenio;
use App\Models\Employee;
use App\Models\MessageTrace;
use App\Models\Sector;
use App\Models\Territory;
use App\Services\Agent\PlannerClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sprint 13, build step 6 (plan.md §C.10) — `agent:replay` is read-only:
 * it re-calls `/plan` and prints a diff, and must not persist a new
 * message, card, or trace row.
 */
class Sprint13AgentReplayTest extends TestCase
{
    use RefreshDatabase;

    public function test_replay_is_read_only_and_reaches_the_planner_with_the_recorded_prior_steps(): void
    {
        $territory = Territory::create(['code' => '31', 'name' => 'Navarra', 'level' => 'provincial', 'aliases' => []]);
        $sector = Sector::create(['name' => 'Hostelería', 'aliases' => []]);
        $convenio = Convenio::create([
            'numero' => '13RP-1', 'name' => 'Convenio replay',
            'territory_id' => $territory->id, 'sector_id' => $sector->id,
        ]);
        $employee = Employee::create([
            'email' => 'replay@example.com', 'full_name' => 'Replay Test',
            'convenio_id' => $convenio->id, 'territory_id' => $territory->id,
            'employment_type' => 'full_time', 'status' => 'active',
        ]);
        $session = ChatSession::create(['employee_id' => $employee->id, 'started_at' => now(), 'last_activity_at' => now()]);
        ChatMessage::create(['session_id' => $session->id, 'role' => 'user', 'content' => '¿cuántas vacaciones tengo?']);
        $assistant = ChatMessage::create(['session_id' => $session->id, 'role' => 'assistant', 'content' => 'derivado']);
        MessageTrace::create(['message_id' => $assistant->id, 'trace' => [
            'engine' => 'agent',
            'agent' => [
                'window' => ['message_ids' => []],
                'steps' => [
                    ['i' => 0, 'type' => 'planner_round', 'round' => 1, 'calls' => [['id' => 't1', 'tool' => 'escalate', 'input' => ['category' => 'other', 'reason' => 'x']]], 'prompt_version' => 'sha256:old'],
                ],
            ],
        ]]);

        $seen = (object) ['question' => null, 'prior' => null, 'calls' => 0];
        $this->app->bind(PlannerClient::class, fn () => new class($seen) implements PlannerClient
        {
            public function __construct(private object $seen) {}

            public function plan(string $question, array $scopeSummary, array $window, array $toolDefinitions, array $priorSteps): array
            {
                $this->seen->question = $question;
                $this->seen->prior = $priorSteps;
                $this->seen->calls++;

                return [
                    'stop_reason' => 'tool_use',
                    'calls' => [['id' => 't1', 'tool' => 'escalate', 'input' => ['category' => 'other', 'reason' => 'x']]],
                    'model' => 'claude-sonnet-5',
                    'request_id' => 'r',
                    'prompt_version' => 'sha256:new',
                    'tokens' => [],
                    'ms' => 1,
                ];
            }
        });

        $messagesBefore = ChatMessage::count();
        $tracesBefore = MessageTrace::count();

        $this->artisan('agent:replay', ['message_id' => $assistant->id])
            ->assertSuccessful();

        $this->assertSame(1, $seen->calls);
        $this->assertSame('¿cuántas vacaciones tengo?', $seen->question);
        $this->assertSame([], $seen->prior);
        $this->assertSame($messagesBefore, ChatMessage::count());
        $this->assertSame($tracesBefore, MessageTrace::count());
    }
}
