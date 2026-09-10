<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\Convenio;
use App\Models\Employee;
use App\Models\Sector;
use App\Models\Territory;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Sprint 8, Step 8 (plan.md §7, ADR-0030) — `message_feedback`. Additive and
 * orthogonal: nothing in the answer loop reads this back (the hard
 * "answer-loop-frozen" constraint stays true by construction — none of these
 * tests touch `ChatService`/the router/the floor decision at all).
 */
class Sprint8FeedbackTest extends TestCase
{
    use RefreshDatabase;

    private Employee $employee;

    private Employee $otherEmployee;

    private ChatMessage $assistantMessage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        $territory = Territory::create(['code' => '01', 'name' => 'Álava', 'level' => 'provincial', 'aliases' => []]);
        $sector = Sector::create(['name' => 'Test Sector', 'aliases' => []]);
        $convenio = Convenio::create(['numero' => '01TESTM001', 'name' => 'Test Convenio', 'territory_id' => $territory->id, 'sector_id' => $sector->id]);

        $this->employee = Employee::create([
            'uuid' => (string) Str::uuid(), 'email' => 'fb-emp@example.com', 'full_name' => 'Feedback Worker',
            'convenio_id' => $convenio->id, 'territory_id' => $territory->id, 'employment_type' => 'full_time', 'status' => 'active',
        ]);
        $this->otherEmployee = Employee::create([
            'uuid' => (string) Str::uuid(), 'email' => 'fb-other@example.com', 'full_name' => 'Other Worker',
            'convenio_id' => $convenio->id, 'territory_id' => $territory->id, 'employment_type' => 'full_time', 'status' => 'active',
        ]);

        $session = ChatSession::create(['employee_id' => $this->employee->id, 'started_at' => now(), 'last_activity_at' => now()]);
        ChatMessage::create(['session_id' => $session->id, 'role' => 'user', 'content' => '¿Cuántos días de vacaciones tengo?']);
        $this->assistantMessage = ChatMessage::create(['session_id' => $session->id, 'role' => 'assistant', 'content' => 'Tienes 22 días.']);
    }

    private function employeeHeaders(Employee $employee): array
    {
        return ['Authorization' => 'Bearer '.$employee->createToken('t')->plainTextToken, 'Accept' => 'application/json'];
    }

    public function test_employee_can_submit_thumbs_up_feedback(): void
    {
        $res = $this->postJson(
            "/chat/message/{$this->assistantMessage->id}/feedback",
            ['rating' => 'up'],
            $this->employeeHeaders($this->employee),
        );

        $res->assertOk();
        $res->assertJsonPath('feedback.rating', 'up');
        $this->assertDatabaseHas('message_feedback', [
            'message_id' => $this->assistantMessage->id,
            'employee_id' => $this->employee->id,
            'rating' => 'up',
        ]);
    }

    public function test_second_click_replaces_not_duplicates(): void
    {
        $headers = $this->employeeHeaders($this->employee);
        $this->postJson("/chat/message/{$this->assistantMessage->id}/feedback", ['rating' => 'up'], $headers)->assertOk();
        $this->postJson("/chat/message/{$this->assistantMessage->id}/feedback", ['rating' => 'down', 'comment' => 'No era correcto'], $headers)->assertOk();

        $this->assertDatabaseCount('message_feedback', 1);
        $this->assertDatabaseHas('message_feedback', [
            'message_id' => $this->assistantMessage->id,
            'rating' => 'down',
            'comment' => 'No era correcto',
        ]);
    }

    public function test_employee_cannot_rate_another_employees_message(): void
    {
        $res = $this->postJson(
            "/chat/message/{$this->assistantMessage->id}/feedback",
            ['rating' => 'up'],
            $this->employeeHeaders($this->otherEmployee),
        );

        $res->assertStatus(404);
        $this->assertDatabaseCount('message_feedback', 0);
    }

    public function test_rating_a_user_turn_is_rejected(): void
    {
        $userMessage = ChatMessage::where('session_id', $this->assistantMessage->session_id)->where('role', 'user')->first();

        $res = $this->postJson(
            "/chat/message/{$userMessage->id}/feedback",
            ['rating' => 'up'],
            $this->employeeHeaders($this->employee),
        );

        $res->assertStatus(404);
    }

    public function test_invalid_rating_value_is_rejected(): void
    {
        $res = $this->postJson(
            "/chat/message/{$this->assistantMessage->id}/feedback",
            ['rating' => 'sideways'],
            $this->employeeHeaders($this->employee),
        );

        $res->assertStatus(422);
    }

    public function test_admin_cannot_use_the_employee_feedback_endpoint(): void
    {
        $admin = Admin::create(['email' => 'fb-admin@example.com', 'full_name' => 'Admin', 'status' => 'active']);
        $res = $this->postJson(
            "/chat/message/{$this->assistantMessage->id}/feedback",
            ['rating' => 'up'],
            ['Authorization' => 'Bearer '.$admin->createToken('t')->plainTextToken, 'Accept' => 'application/json'],
        );

        $res->assertStatus(403);
    }

    public function test_satisfaction_rate_shows_up_on_the_analytics_deflection_endpoint(): void
    {
        // A second assistant turn so up/down produces a non-trivial 50% rate.
        $secondSession = ChatSession::create(['employee_id' => $this->employee->id, 'started_at' => now(), 'last_activity_at' => now()]);
        $secondAssistantMessage = ChatMessage::create(['session_id' => $secondSession->id, 'role' => 'assistant', 'content' => 'Otra respuesta.']);

        $headers = $this->employeeHeaders($this->employee);
        $this->postJson("/chat/message/{$this->assistantMessage->id}/feedback", ['rating' => 'up'], $headers)->assertOk();
        $this->postJson("/chat/message/{$secondAssistantMessage->id}/feedback", ['rating' => 'down'], $headers)->assertOk();

        $admin = Admin::create(['email' => 'fb-super@example.com', 'full_name' => 'Super', 'status' => 'active']);
        $admin->assignRole('super_admin');
        $this->app['auth']->forgetGuards();
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $adminHeaders = ['Authorization' => 'Bearer '.$admin->createToken('t')->plainTextToken, 'Accept' => 'application/json'];

        $res = $this->getJson('/admin/analytics/deflection', $adminHeaders);
        $res->assertOk();
        $res->assertJsonPath('satisfaction.up', 1);
        $res->assertJsonPath('satisfaction.down', 1);
        $res->assertJsonPath('satisfaction.rate', 0.5);
    }
}
