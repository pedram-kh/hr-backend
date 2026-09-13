<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\Convenio;
use App\Models\ConvenioGroup;
use App\Models\ConvenioJobCategory;
use App\Models\Employee;
use App\Models\Sector;
use App\Models\Territory;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sprint 10b, Correction-02, Fix 1 (eyes-on finding) — the History conversation
 * modal showed no employee context beyond the header name. Mirrors Sprint 10a
 * Correction-02's escalation-card `employee_context` block (name/email/
 * territory/category-group/seniority), reusing the SAME shape via
 * `App\Support\EmployeeContextPresenter`, but on `HistoryController::show()`.
 *
 *   - Any `history.view_all` holder (auditor, super_admin) sees the block —
 *     no new/narrower ability: this endpoint already requires `history.view_all`
 *     for the conversation itself, so the block rides the same gate ("no new
 *     access path").
 *   - `seniority` is null exactly when `start_date` is unset ("where recorded"),
 *     same rule as the escalation-card block.
 *   - The LIST endpoint (`GET /admin/history/conversations`) never carries this
 *     block, proving "detail modal only" server-side.
 *   - Opening the conversation still writes exactly one `conversation_access_log`
 *     row — this fix enriches an already-logged read, it does not add a new one.
 */
class Sprint10bCorrection02HistoryEmployeeContextTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function adminWithRole(string $role): Admin
    {
        $admin = Admin::create([
            'email' => $role.'-'.uniqid().'@example.com', 'full_name' => ucfirst($role), 'status' => 'active',
        ]);
        $admin->assignRole($role);

        return $admin;
    }

    /** @return array{session: ChatSession, employee: Employee} */
    private function makeSessionWithEmployee(?string $startDate = '2020-06-01'): array
    {
        $territory = Territory::create(['code' => '48', 'name' => 'Bizkaia', 'level' => 'provincial', 'aliases' => []]);
        $sector = Sector::create(['name' => 'Sector C2-2b', 'aliases' => []]);
        $convenio = Convenio::create([
            'numero' => 'C22B'.uniqid(), 'name' => 'Convenio C2-2b',
            'territory_id' => $territory->id, 'sector_id' => $sector->id,
        ]);
        $jobCategory = ConvenioJobCategory::create([
            'convenio_id' => $convenio->id, 'name' => 'Auxiliar Administrativo/a', 'group_code' => null,
        ]);
        $group = ConvenioGroup::create([
            'convenio_id' => $convenio->id, 'parent_id' => null, 'code_normalized' => 'grupo-3',
            'label' => 'Grupo 3', 'status' => ConvenioGroup::STATUS_APPROVED, 'source' => ConvenioGroup::SOURCE_MANUAL,
        ]);
        $employee = Employee::create([
            'email' => 'c22b-'.uniqid().'@example.com', 'full_name' => 'Empleada C2-2b',
            'convenio_id' => $convenio->id, 'job_category_id' => $jobCategory->id,
            'convenio_group_id' => $group->id, 'territory_id' => $territory->id,
            'employment_type' => 'full_time', 'status' => 'active', 'start_date' => $startDate,
        ]);
        $session = ChatSession::create(['employee_id' => $employee->id, 'started_at' => now(), 'last_activity_at' => now()]);
        ChatMessage::create(['session_id' => $session->id, 'role' => 'user', 'content' => 'pregunta sobre mi convenio']);
        ChatMessage::create(['session_id' => $session->id, 'role' => 'assistant', 'content' => 'respuesta']);

        return ['session' => $session, 'employee' => $employee];
    }

    public function test_auditor_sees_the_full_employee_block_on_conversation_open(): void
    {
        ['session' => $session, 'employee' => $employee] = $this->makeSessionWithEmployee('2020-06-01');
        $admin = $this->adminWithRole('auditor'); // history.view_all, no escalation.work
        $token = $admin->createToken('t')->plainTextToken;

        $res = $this->withHeader('Authorization', "Bearer $token")->getJson("/admin/history/conversations/{$session->uuid}");
        $res->assertOk();

        $ctx = $res->json('employee_context');
        $this->assertNotNull($ctx);
        $this->assertSame($employee->full_name, $ctx['full_name']);
        $this->assertSame($employee->email, $ctx['email']);
        $this->assertSame('Bizkaia', $ctx['territory']['name']);
        $this->assertSame('Auxiliar Administrativo/a', $ctx['job_category']['name']);
        $this->assertSame('Grupo 3', $ctx['convenio_group']['path_label']);
        $this->assertSame('2020-06-01', $ctx['seniority']['start_date']);
        $this->assertIsInt($ctx['seniority']['years']);
    }

    public function test_super_admin_also_sees_the_employee_block(): void
    {
        ['session' => $session] = $this->makeSessionWithEmployee();
        $admin = $this->adminWithRole('super_admin');
        $token = $admin->createToken('t')->plainTextToken;

        $res = $this->withHeader('Authorization', "Bearer $token")->getJson("/admin/history/conversations/{$session->uuid}");
        $res->assertOk();
        $this->assertNotNull($res->json('employee_context'));
    }

    public function test_seniority_is_null_when_start_date_not_recorded(): void
    {
        ['session' => $session] = $this->makeSessionWithEmployee(null);
        $admin = $this->adminWithRole('auditor');
        $token = $admin->createToken('t')->plainTextToken;

        $res = $this->withHeader('Authorization', "Bearer $token")->getJson("/admin/history/conversations/{$session->uuid}");
        $res->assertOk();
        $this->assertNull($res->json('employee_context.seniority'));
        // Everything else stays present — only seniority narrows.
        $this->assertNotNull($res->json('employee_context.full_name'));
    }

    /**
     * Server-side proof of "detail modal only" — the list endpoint's row
     * shape never carries this block, so it cannot leak onto the History
     * table no matter what the frontend does with the response.
     */
    public function test_list_endpoint_never_carries_the_employee_block(): void
    {
        $this->makeSessionWithEmployee();
        $admin = $this->adminWithRole('auditor');
        $token = $admin->createToken('t')->plainTextToken;

        $res = $this->withHeader('Authorization', "Bearer $token")->getJson('/admin/history/conversations');
        $res->assertOk();
        $rows = $res->json('data');
        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertArrayNotHasKey('employee_context', $row);
        }
    }

    /**
     * This fix enriches an already-logged read; it must not add a SECOND
     * access-log row for the same open.
     */
    public function test_opening_the_conversation_still_writes_exactly_one_access_log_row(): void
    {
        ['session' => $session] = $this->makeSessionWithEmployee();
        $admin = $this->adminWithRole('auditor');
        $token = $admin->createToken('t')->plainTextToken;

        $this->withHeader('Authorization', "Bearer $token")->getJson("/admin/history/conversations/{$session->uuid}")->assertOk();

        $this->assertDatabaseCount('conversation_access_log', 1);
    }
}
