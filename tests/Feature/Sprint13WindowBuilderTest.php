<?php

namespace Tests\Feature;

use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\Convenio;
use App\Models\Employee;
use App\Models\MessageTrace;
use App\Models\Sector;
use App\Models\Territory;
use App\Services\Agent\WindowBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sprint 13, build step 5 leftover / step 6 consumer (plan.md §B.4.4) —
 * the conversation window excludes `hr_agent`, other sessions, cards and
 * answer text; caps user text; includes an `ask` turn's question verbatim.
 */
class Sprint13WindowBuilderTest extends TestCase
{
    use RefreshDatabase;

    private function makeSession(): ChatSession
    {
        $territory = Territory::create(['code' => '31', 'name' => 'Navarra', 'level' => 'provincial', 'aliases' => []]);
        $sector = Sector::create(['name' => 'Hostelería', 'aliases' => []]);
        $convenio = Convenio::create([
            'numero' => '13WB-1', 'name' => 'Convenio WB',
            'territory_id' => $territory->id, 'sector_id' => $sector->id,
        ]);
        $employee = Employee::create([
            'email' => 'wb@example.com', 'full_name' => 'Window Builder',
            'convenio_id' => $convenio->id, 'territory_id' => $territory->id,
            'employment_type' => 'full_time', 'status' => 'active',
        ]);

        return ChatSession::create(['employee_id' => $employee->id, 'started_at' => now(), 'last_activity_at' => now()]);
    }

    public function test_excludes_hr_agent_and_other_sessions_and_strips_answer_text(): void
    {
        $session = $this->makeSession();
        $other = ChatSession::create(['employee_id' => $session->employee_id, 'started_at' => now(), 'last_activity_at' => now()]);

        $u1 = ChatMessage::create(['session_id' => $session->id, 'role' => 'user', 'content' => '¿vacaciones?']);
        $a1 = ChatMessage::create(['session_id' => $session->id, 'role' => 'assistant', 'content' => 'Tienes 37 días — ESTO NO DEBE IR A LA VENTANA']);
        MessageTrace::create(['message_id' => $a1->id, 'trace' => [
            'floor_decision' => ['outcome' => 'answer', 'path' => 'prose'],
            'agent' => ['steps' => [['type' => 'tool_call', 'tool' => 'convenio_search']]],
        ]]);
        ChatMessage::create(['session_id' => $session->id, 'role' => 'hr_agent', 'content' => 'Respuesta humana — tampoco']);
        ChatMessage::create(['session_id' => $other->id, 'role' => 'user', 'content' => 'otra sesión']);
        ChatMessage::create(['session_id' => $other->id, 'role' => 'assistant', 'content' => 'otra sesión']);

        $window = app(WindowBuilder::class)->build($session);

        $this->assertSame([$u1->id, $a1->id], $window['message_ids']);
        $this->assertCount(1, $window['exchanges']);
        $this->assertSame('¿vacaciones?', $window['exchanges'][0]['user_question']);
        $this->assertSame('answer', $window['exchanges'][0]['outcome']);
        $this->assertSame(['convenio_search'], $window['exchanges'][0]['tools_used']);
        $this->assertArrayNotHasKey('question_text', $window['exchanges'][0]);
        $this->assertStringNotContainsString('37 días', json_encode($window));
        $this->assertStringNotContainsString('Respuesta humana', json_encode($window));
        $this->assertStringNotContainsString('otra sesión', json_encode($window));
    }

    public function test_ask_turn_includes_the_question_verbatim_and_user_text_is_capped(): void
    {
        $session = $this->makeSession();
        $long = str_repeat('x', 600);
        ChatMessage::create(['session_id' => $session->id, 'role' => 'user', 'content' => $long]);
        $ask = ChatMessage::create(['session_id' => $session->id, 'role' => 'assistant', 'content' => '¿Te refieres a 2025 o a 2026?']);
        MessageTrace::create(['message_id' => $ask->id, 'trace' => ['floor_decision' => ['outcome' => 'ask', 'path' => 'agent_ask_employee']]]);

        $window = app(WindowBuilder::class)->build($session);

        $this->assertSame(500, mb_strlen($window['exchanges'][0]['user_question']));
        $this->assertSame('¿Te refieres a 2025 o a 2026?', $window['exchanges'][0]['question_text']);
    }

    public function test_keeps_only_the_last_three_exchanges(): void
    {
        $session = $this->makeSession();
        $ids = [];
        for ($i = 0; $i < 4; $i++) {
            $u = ChatMessage::create(['session_id' => $session->id, 'role' => 'user', 'content' => "q{$i}"]);
            $a = ChatMessage::create(['session_id' => $session->id, 'role' => 'assistant', 'content' => "a{$i}"]);
            MessageTrace::create(['message_id' => $a->id, 'trace' => ['floor_decision' => ['outcome' => 'answer']]]);
            $ids[] = [$u->id, $a->id];
        }

        $window = app(WindowBuilder::class)->build($session);
        $this->assertCount(3, $window['exchanges']);
        $this->assertSame([$ids[1][0], $ids[1][1], $ids[2][0], $ids[2][1], $ids[3][0], $ids[3][1]], $window['message_ids']);
    }
}
