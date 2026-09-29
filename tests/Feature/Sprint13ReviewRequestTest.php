<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\Convenio;
use App\Models\Employee;
use App\Models\EscalationCard;
use App\Models\MessageTrace;
use App\Models\Sector;
use App\Models\Territory;
use App\Support\EscalationExplainer;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Sprint 13, build step 8 (plan.md §D.13/§E.15) — "¿Quieres que lo revise
 * RR. HH.?", `POST /chat/message/{id}/review`. Self-scoped exactly like
 * `Sprint8FeedbackTest` (the endpoint it is modelled on): answer-only,
 * idempotent (the DB's own partial-unique-index guarantee, exercised here at
 * the request layer), and the created card's `explanation_facts` come from
 * the SAME `EscalationExplainer` every other reason uses — never a bespoke
 * copy.
 */
class Sprint13ReviewRequestTest extends TestCase
{
    use RefreshDatabase;

    private Employee $employee;

    private Employee $otherEmployee;

    private ChatMessage $userMessage;

    private ChatMessage $answeredMessage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        $territory = Territory::create(['code' => '01', 'name' => 'Álava', 'level' => 'provincial', 'aliases' => []]);
        $sector = Sector::create(['name' => 'Test Sector', 'aliases' => []]);
        $convenio = Convenio::create(['numero' => '01TESTM002', 'name' => 'Test Convenio', 'territory_id' => $territory->id, 'sector_id' => $sector->id]);

        $this->employee = Employee::create([
            'uuid' => (string) Str::uuid(), 'email' => 'review-emp@example.com', 'full_name' => 'Review Worker',
            'convenio_id' => $convenio->id, 'territory_id' => $territory->id, 'employment_type' => 'full_time', 'status' => 'active',
        ]);
        $this->otherEmployee = Employee::create([
            'uuid' => (string) Str::uuid(), 'email' => 'review-other@example.com', 'full_name' => 'Other Worker',
            'convenio_id' => $convenio->id, 'territory_id' => $territory->id, 'employment_type' => 'full_time', 'status' => 'active',
        ]);

        $session = ChatSession::create(['employee_id' => $this->employee->id, 'started_at' => now(), 'last_activity_at' => now()]);
        $this->userMessage = ChatMessage::create(['session_id' => $session->id, 'role' => 'user', 'content' => '¿Cuántos días de vacaciones tengo?']);
        $this->answeredMessage = ChatMessage::create(['session_id' => $session->id, 'role' => 'assistant', 'content' => 'Tienes 22 días.']);
        MessageTrace::create(['message_id' => $this->answeredMessage->id, 'trace' => ['floor_decision' => ['outcome' => 'answer']]]);
    }

    private function employeeHeaders(Employee $employee): array
    {
        return ['Authorization' => 'Bearer '.$employee->createToken('t')->plainTextToken, 'Accept' => 'application/json'];
    }

    private function turnWithOutcome(string $outcome): ChatMessage
    {
        $session = ChatSession::where('employee_id', $this->employee->id)->first();
        $message = ChatMessage::create(['session_id' => $session->id, 'role' => 'assistant', 'content' => 'x']);
        MessageTrace::create(['message_id' => $message->id, 'trace' => ['floor_decision' => ['outcome' => $outcome]]]);

        return $message;
    }

    public function test_employee_can_request_review_of_their_own_answered_message(): void
    {
        $res = $this->postJson(
            "/chat/message/{$this->answeredMessage->id}/review",
            [],
            $this->employeeHeaders($this->employee),
        );

        $res->assertOk();
        $uuid = $res->json('escalation_uuid');
        $this->assertNotNull($uuid);

        $this->assertDatabaseHas('escalation_cards', [
            'uuid' => $uuid,
            'reason' => 'employee_requested_review',
            'status' => 'new',
            'employee_id' => $this->employee->id,
            'reviewed_message_id' => $this->answeredMessage->id,
            'source_message_id' => $this->userMessage->id,
        ]);
    }

    public function test_second_request_on_the_same_message_is_idempotent(): void
    {
        $headers = $this->employeeHeaders($this->employee);
        $first = $this->postJson("/chat/message/{$this->answeredMessage->id}/review", [], $headers);
        $second = $this->postJson("/chat/message/{$this->answeredMessage->id}/review", [], $headers);

        $first->assertOk();
        $second->assertOk();
        $this->assertSame($first->json('escalation_uuid'), $second->json('escalation_uuid'));
        $this->assertDatabaseCount('escalation_cards', 1);
    }

    public function test_employee_cannot_request_review_of_another_employees_message(): void
    {
        $res = $this->postJson(
            "/chat/message/{$this->answeredMessage->id}/review",
            [],
            $this->employeeHeaders($this->otherEmployee),
        );

        $res->assertStatus(404);
        $this->assertDatabaseCount('escalation_cards', 0);
    }

    public function test_an_already_escalated_message_cannot_be_reviewed(): void
    {
        $escalated = $this->turnWithOutcome('escalate');

        $res = $this->postJson(
            "/chat/message/{$escalated->id}/review",
            [],
            $this->employeeHeaders($this->employee),
        );

        $res->assertStatus(422);
        $this->assertDatabaseCount('escalation_cards', 0);
    }

    public function test_an_ask_outcome_message_cannot_be_reviewed(): void
    {
        $ask = $this->turnWithOutcome('ask');

        $res = $this->postJson(
            "/chat/message/{$ask->id}/review",
            [],
            $this->employeeHeaders($this->employee),
        );

        $res->assertStatus(422);
        $this->assertDatabaseCount('escalation_cards', 0);
    }

    public function test_a_needs_category_message_cannot_be_reviewed(): void
    {
        $pick = $this->turnWithOutcome('needs_category');

        $res = $this->postJson(
            "/chat/message/{$pick->id}/review",
            [],
            $this->employeeHeaders($this->employee),
        );

        $res->assertStatus(422);
    }

    public function test_a_user_turn_cannot_be_reviewed(): void
    {
        $res = $this->postJson(
            "/chat/message/{$this->userMessage->id}/review",
            [],
            $this->employeeHeaders($this->employee),
        );

        $res->assertStatus(404);
    }

    public function test_admin_cannot_use_the_employee_review_endpoint(): void
    {
        $admin = Admin::create(['email' => 'review-admin@example.com', 'full_name' => 'Admin', 'status' => 'active']);
        $res = $this->postJson(
            "/chat/message/{$this->answeredMessage->id}/review",
            [],
            ['Authorization' => 'Bearer '.$admin->createToken('t')->plainTextToken, 'Accept' => 'application/json'],
        );

        $res->assertStatus(403);
    }

    public function test_the_created_cards_explanation_facts_come_from_the_shared_explainer(): void
    {
        $this->postJson(
            "/chat/message/{$this->answeredMessage->id}/review",
            [],
            $this->employeeHeaders($this->employee),
        )->assertOk();

        $card = EscalationCard::where('reviewed_message_id', $this->answeredMessage->id)->first();
        $expected = EscalationExplainer::explain('employee_requested_review', ['floor_decision' => ['outcome' => 'answer']]);

        $this->assertSame($expected['asked'], $card->explanation_facts['asked']);
        $this->assertSame($expected['found'], $card->explanation_facts['found']);
        $this->assertSame($expected['fix_action'], $card->explanation_facts['fix_action']);
        $this->assertSame($expected['fix_action'], $card->fix_action);
        $this->assertSame($expected['fix_surface'], $card->fix_surface);
        $this->assertNull($card->fix_link);
    }
}
