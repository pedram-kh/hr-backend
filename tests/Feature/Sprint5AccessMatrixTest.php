<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\Convenio;
use App\Models\ConvenioJobCategory;
use App\Models\ConversationAccessLog;
use App\Models\Employee;
use App\Models\EmployeeAuditLog;
use App\Models\EscalationCard;
use App\Models\Sector;
use App\Models\Territory;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Sprint 5 acceptance proof (ADR-0018): role-scoped conversation access is
 * enforced SERVER-SIDE, proven by hitting endpoints directly (not via the UI).
 * Every History access writes conversation_access_log; every directory change
 * writes employee_audit_log; deactivation removes access.
 */
class Sprint5AccessMatrixTest extends TestCase
{
    use RefreshDatabase;

    private Territory $territory;

    private Convenio $convenio;

    private ConvenioJobCategory $jobCategory;

    private Employee $employee;

    private ChatSession $session;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class); // roles + all abilities (idempotent)
        $this->buildWorld();
    }

    private function buildWorld(): void
    {
        $this->territory = Territory::create(['code' => '01', 'name' => 'Álava', 'level' => 'provincial', 'aliases' => []]);
        $sector = Sector::create(['name' => 'Test Sector', 'aliases' => []]);
        $this->convenio = Convenio::create([
            'numero' => '01TEST0001', 'name' => 'Test Convenio',
            'territory_id' => $this->territory->id, 'sector_id' => $sector->id,
        ]);
        $this->jobCategory = ConvenioJobCategory::create([
            'convenio_id' => $this->convenio->id, 'name' => 'Técnico/a', 'group_code' => null,
        ]);
        $this->employee = Employee::create([
            'email' => 'worker@example.com', 'full_name' => 'Worker One',
            'convenio_id' => $this->convenio->id, 'job_category_id' => $this->jobCategory->id,
            'territory_id' => $this->territory->id, 'employment_type' => 'full_time', 'status' => 'active',
        ]);
        $this->session = ChatSession::create([
            'employee_id' => $this->employee->id, 'started_at' => now(), 'last_activity_at' => now(),
        ]);
        $userMsg = ChatMessage::create(['session_id' => $this->session->id, 'role' => 'user', 'content' => 'tengo una duda sobre mis vacaciones']);
        ChatMessage::create(['session_id' => $this->session->id, 'role' => 'assistant', 'content' => 'respuesta del asistente']);
        EscalationCard::create([
            'chat_session_id' => $this->session->id, 'source_message_id' => $userMsg->id,
            'employee_id' => $this->employee->id, 'reason' => 'low_confidence', 'status' => 'new',
        ]);
    }

    private function adminWithRole(string $role, string $status = 'active'): Admin
    {
        $admin = Admin::create([
            'email' => $role.'-'.uniqid().'@example.com', 'full_name' => ucfirst($role), 'status' => $status,
        ]);
        $admin->assignRole($role);

        return $admin;
    }

    private function token(Admin $admin): string
    {
        return $admin->createToken('test')->plainTextToken;
    }

    /** @return array<string,string> */
    private function auth(Admin $admin): array
    {
        return ['Authorization' => 'Bearer '.$this->token($admin), 'Accept' => 'application/json'];
    }

    /**
     * Reset per-request state before a sub-request. In production each HTTP
     * request is a fresh app + fresh guard, so the Bearer token re-resolves the
     * user and the role→permission map loads fresh. The test client reuses ONE
     * app instance across many sub-requests, and the auth guard caches the FIRST
     * resolved user (so a later request with a different token would be
     * mis-evaluated as the first user) — a test-harness artifact, not a
     * production bug. forgetGuards() forces re-resolution from the token;
     * forgetCachedPermissions() reloads the spatie map. Together they mirror the
     * real per-request condition. (Confirmed: a single fresh request resolves the
     * correct user + can() for every role.)
     */
    private function resetPermCache(): void
    {
        $this->app['auth']->forgetGuards();
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function getAs(Admin $admin, string $url): \Illuminate\Testing\TestResponse
    {
        $this->resetPermCache();

        return $this->getJson($url, $this->auth($admin));
    }

    // ---- C. Role-scoped access — server enforcement -------------------------

    public function test_hr_agent_is_denied_every_history_endpoint_403(): void
    {
        $hr = $this->adminWithRole('hr_agent');

        $this->getAs($hr, '/admin/history/conversations')->assertStatus(403);
        $this->getAs($hr, '/admin/history/search?q=vacaciones')->assertStatus(403);
        $this->getAs($hr, '/admin/history/conversations/'.$this->session->uuid)->assertStatus(403);
        $this->getAs($hr, '/admin/history/employees/'.$this->employee->uuid)->assertStatus(403);
    }

    public function test_knowledge_editor_is_denied_all_conversation_reads_including_card_payload(): void
    {
        $ke = $this->adminWithRole('knowledge_editor');

        // Full history: forbidden.
        $this->getAs($ke, '/admin/history/conversations')->assertStatus(403);

        // Card detail: meta readable, but the CONVERSATION payload is gated (Q5/§4.4).
        $card = EscalationCard::first();
        $this->getAs($ke, '/admin/escalations/'.$card->uuid)
            ->assertStatus(200)
            ->assertJson(['conversation' => [], 'conversation_restricted' => true]);
    }

    public function test_hr_agent_card_scoped_conversation_still_works_unchanged(): void
    {
        $hr = $this->adminWithRole('hr_agent');
        $card = EscalationCard::first();

        $res = $this->getAs($hr, '/admin/escalations/'.$card->uuid)->assertStatus(200);
        $res->assertJson(['conversation_restricted' => false]);
        $this->assertNotEmpty($res->json('conversation'));
    }

    public function test_auditor_reaches_history_read_only_and_access_is_logged(): void
    {
        $auditor = $this->adminWithRole('auditor');

        $this->getAs($auditor, '/admin/history/conversations')->assertStatus(200);

        $this->assertSame(0, ConversationAccessLog::count());
        $this->getAs($auditor, '/admin/history/conversations/'.$this->session->uuid)->assertStatus(200);

        $this->assertDatabaseHas('conversation_access_log', [
            'admin_id' => $auditor->id,
            'employee_id' => $this->employee->id,
            'chat_session_id' => $this->session->id,
            'access_type' => ConversationAccessLog::TYPE_VIEW,
        ]);
    }

    public function test_super_admin_full_access_and_even_own_read_is_logged(): void
    {
        $super = $this->adminWithRole('super_admin');

        $this->getAs($super, '/admin/history/conversations/'.$this->session->uuid)->assertStatus(200);

        // No one is above the log — a super_admin's read writes a row too.
        $this->assertDatabaseHas('conversation_access_log', [
            'admin_id' => $super->id,
            'access_type' => ConversationAccessLog::TYPE_VIEW,
        ]);
    }

    public function test_search_logs_a_history_search_event(): void
    {
        $super = $this->adminWithRole('super_admin');

        $this->getAs($super, '/admin/history/search?q=vacaciones')
            ->assertStatus(200)
            ->assertJsonStructure(['query', 'matches']);

        $this->assertDatabaseHas('conversation_access_log', [
            'admin_id' => $super->id,
            'access_type' => ConversationAccessLog::TYPE_SEARCH,
        ]);
    }

    // ---- B. Directory + admin gating ----------------------------------------

    public function test_directory_reads_gated_to_directory_manage(): void
    {
        $this->getAs($this->adminWithRole('knowledge_editor'), '/admin/employees')->assertStatus(403);
        $this->getAs($this->adminWithRole('auditor'), '/admin/employees')->assertStatus(403);
        $this->getAs($this->adminWithRole('hr_agent'), '/admin/employees')->assertStatus(200);
        $this->getAs($this->adminWithRole('super_admin'), '/admin/employees')->assertStatus(200);
    }

    public function test_admin_management_is_super_admin_only(): void
    {
        $this->getAs($this->adminWithRole('hr_agent'), '/admin/admins')->assertStatus(403);
        $this->getAs($this->adminWithRole('auditor'), '/admin/admins')->assertStatus(403);
        $this->getAs($this->adminWithRole('super_admin'), '/admin/admins')->assertStatus(200);
    }

    // ---- Deactivation removes access ----------------------------------------

    public function test_deactivated_admin_is_refused(): void
    {
        $admin = $this->adminWithRole('super_admin');
        $token = $this->token($admin);
        $h = ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];

        $this->resetPermCache();
        $this->getJson('/admin/employees', $h)->assertStatus(200);

        // Deactivate (the management path revokes tokens; assert access ends).
        $admin->update(['status' => 'inactive']);
        $admin->tokens()->delete();

        $this->resetPermCache();
        $res = $this->getJson('/admin/employees', $h);
        $this->assertContains($res->getStatusCode(), [401, 403]);
    }

    public function test_inactive_employee_cannot_chat(): void
    {
        $this->employee->update(['status' => 'inactive']);
        $token = $this->employee->createToken('test')->plainTextToken;

        $res = $this->getJson('/chat/session', ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json']);
        $this->assertSame(403, $res->getStatusCode());
    }

    // ---- A. Directory change writes audit; email-change 409 -----------------

    public function test_editing_convenio_writes_employee_audit_log(): void
    {
        $other = Convenio::create([
            'numero' => '01TEST0002', 'name' => 'Other Convenio',
            'territory_id' => $this->territory->id, 'sector_id' => $this->convenio->sector_id,
        ]);
        $this->resetPermCache();
        $h = $this->auth($this->adminWithRole('super_admin'));

        $this->patchJson('/admin/employees/'.$this->employee->uuid, [
            'email' => $this->employee->email,
            'full_name' => $this->employee->full_name,
            'convenio_id' => $other->id,
            'job_category_id' => null,
            'territory_id' => $this->territory->id,
            'employment_type' => 'full_time',
        ], $h)->assertStatus(200);

        $this->assertDatabaseHas('employee_audit_log', [
            'employee_id' => $this->employee->id,
            'field_changed' => 'convenio_id',
            'old_value' => (string) $this->convenio->id,
            'new_value' => (string) $other->id,
        ]);
    }

    public function test_email_change_requires_confirm_409(): void
    {
        $this->resetPermCache();
        $h = $this->auth($this->adminWithRole('super_admin'));

        $payload = [
            'email' => 'changed@example.com',
            'full_name' => $this->employee->full_name,
            'convenio_id' => $this->convenio->id,
            'job_category_id' => $this->jobCategory->id,
            'territory_id' => $this->territory->id,
            'employment_type' => 'full_time',
        ];

        $this->patchJson('/admin/employees/'.$this->employee->uuid, $payload, $h)
            ->assertStatus(409)
            ->assertJson(['code' => 'email_change_confirmation_required']);

        $this->patchJson('/admin/employees/'.$this->employee->uuid, $payload + ['confirm_email_change' => true], $h)
            ->assertStatus(200);

        $this->assertDatabaseHas('employee_audit_log', [
            'employee_id' => $this->employee->id,
            'field_changed' => 'email',
            'new_value' => 'changed@example.com',
        ]);
    }

    // ---- A. CSV: bad row reported, not dropped ------------------------------

    public function test_csv_import_reports_bad_row_and_applies_valid_rows(): void
    {
        $this->resetPermCache();
        $h = $this->auth($this->adminWithRole('super_admin'));

        $csv = "email,full_name,convenio_numero,employment_type\n"
            ."new1@example.com,New One,01TEST0001,full_time\n"
            .",Missing Email,01TEST0001,full_time\n"
            ."new3@example.com,New Three,DOES-NOT-EXIST,full_time\n";

        $file = UploadedFile::fake()->createWithContent('employees.csv', $csv);

        // Dry run reports the bad rows (not dropped).
        $report = $this->post('/admin/employees/import/validate', ['file' => $file], $h)
            ->assertStatus(200)->json();
        $this->assertSame(3, $report['summary']['total']);
        $this->assertSame(1, $report['summary']['valid']);
        $this->assertSame(2, $report['summary']['invalid']);

        // Apply imports only the valid row + audits it.
        $file2 = UploadedFile::fake()->createWithContent('employees.csv', $csv);
        $applied = $this->post('/admin/employees/import', ['file' => $file2], $h)
            ->assertStatus(200)->json();
        $this->assertSame(1, $applied['summary']['created']);

        $created = Employee::where('email', 'new1@example.com')->first();
        $this->assertNotNull($created);
        $this->assertDatabaseHas('employee_audit_log', ['employee_id' => $created->id, 'field_changed' => '*', 'new_value' => 'created']);
        $this->assertDatabaseMissing('employees', ['email' => 'new3@example.com']);
    }

    public function test_role_assignment_grants_history_view_all(): void
    {
        $super = $this->adminWithRole('super_admin');
        $target = $this->adminWithRole('knowledge_editor');

        // knowledge_editor cannot view history.
        $this->getAs($target, '/admin/history/conversations')->assertStatus(403);

        // super_admin promotes them to auditor through the UI endpoint.
        $this->resetPermCache();
        $this->putJson('/admin/admins/'.$target->uuid.'/roles', ['roles' => ['auditor']], $this->auth($super))
            ->assertStatus(200);

        $this->getAs($target->fresh(), '/admin/history/conversations')->assertStatus(200);
    }
}
