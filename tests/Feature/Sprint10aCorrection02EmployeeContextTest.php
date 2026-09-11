<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\Convenio;
use App\Models\ConvenioGroup;
use App\Models\ConvenioJobCategory;
use App\Models\EscalationCard;
use App\Models\Employee;
use App\Models\Sector;
use App\Models\Territory;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Correction-02 (CP-4 step 6, C2-2) — the card-detail-only employee block.
 *
 *   - escalation.work (hr_agent/super_admin) sees name/email/territory/
 *     category/group/seniority in `GET /admin/escalations/{uuid}`.
 *   - history.view_all-only (auditor) sees the conversation (unaffected,
 *     pre-existing gate) but NOT the employee block — same negative shape
 *     the conversation gate already uses (`*_restricted: true`, no new UI
 *     idiom, no new ability).
 *   - The board LIST endpoint (`GET /admin/escalations`) never carries this
 *     block at all, proving the "detail modal only" constraint server-side,
 *     not just by omission in one frontend component.
 *   - `seniority` is null exactly when `start_date` is unset ("where recorded").
 */
class Sprint10aCorrection02EmployeeContextTest extends TestCase
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

    /** @return array{card: EscalationCard, employee: Employee} */
    private function makeCardWithEmployee(?string $startDate = '2019-03-01'): array
    {
        $territory = Territory::create(['code' => '28', 'name' => 'Madrid', 'level' => 'provincial', 'aliases' => []]);
        $sector = Sector::create(['name' => 'Sector C2-2', 'aliases' => []]);
        $convenio = Convenio::create([
            'numero' => 'C22'.uniqid(), 'name' => 'Convenio C2-2',
            'territory_id' => $territory->id, 'sector_id' => $sector->id,
        ]);
        $jobCategory = ConvenioJobCategory::create([
            'convenio_id' => $convenio->id, 'name' => 'Oficial/a de 1a', 'group_code' => null,
        ]);
        $group = ConvenioGroup::create([
            'convenio_id' => $convenio->id, 'parent_id' => null, 'code_normalized' => 'grupo-2',
            'label' => 'Grupo 2', 'status' => ConvenioGroup::STATUS_APPROVED, 'source' => ConvenioGroup::SOURCE_MANUAL,
        ]);
        $employee = Employee::create([
            'email' => 'c22-'.uniqid().'@example.com', 'full_name' => 'Empleada C2-2',
            'convenio_id' => $convenio->id, 'job_category_id' => $jobCategory->id,
            'convenio_group_id' => $group->id, 'territory_id' => $territory->id,
            'employment_type' => 'full_time', 'status' => 'active', 'start_date' => $startDate,
        ]);
        $session = ChatSession::create(['employee_id' => $employee->id, 'started_at' => now(), 'last_activity_at' => now()]);
        $userMsg = ChatMessage::create(['session_id' => $session->id, 'role' => 'user', 'content' => 'pregunta sobre mi convenio']);
        ChatMessage::create(['session_id' => $session->id, 'role' => 'assistant', 'content' => 'respuesta', 'escalated' => true]);
        $card = EscalationCard::create([
            'chat_session_id' => $session->id, 'source_message_id' => $userMsg->id,
            'employee_id' => $employee->id, 'reason' => 'low_confidence', 'status' => 'new',
        ]);

        return ['card' => $card, 'employee' => $employee];
    }

    public function test_escalation_work_sees_the_full_employee_block(): void
    {
        ['card' => $card, 'employee' => $employee] = $this->makeCardWithEmployee('2019-03-01');
        $admin = $this->adminWithRole('hr_agent');
        $token = $admin->createToken('t')->plainTextToken;

        $res = $this->withHeader('Authorization', "Bearer $token")->getJson("/admin/escalations/{$card->uuid}");
        $res->assertOk();
        $res->assertJsonPath('employee_context_restricted', false);

        $ctx = $res->json('employee_context');
        $this->assertSame($employee->full_name, $ctx['full_name']);
        $this->assertSame($employee->email, $ctx['email']);
        $this->assertSame('Madrid', $ctx['territory']['name']);
        $this->assertSame('Oficial/a de 1a', $ctx['job_category']['name']);
        $this->assertSame('Grupo 2', $ctx['convenio_group']['path_label']);
        $this->assertSame('2019-03-01', $ctx['seniority']['start_date']);
        $this->assertIsInt($ctx['seniority']['years']);
        $this->assertGreaterThanOrEqual(5, $ctx['seniority']['years']); // 2019 → well past 5y by 2026
    }

    public function test_seniority_is_null_when_start_date_not_recorded(): void
    {
        ['card' => $card] = $this->makeCardWithEmployee(null);
        $admin = $this->adminWithRole('hr_agent');
        $token = $admin->createToken('t')->plainTextToken;

        $res = $this->withHeader('Authorization', "Bearer $token")->getJson("/admin/escalations/{$card->uuid}");
        $res->assertOk();
        $this->assertNull($res->json('employee_context.seniority'));
        // Everything else on the block is still present — "where recorded"
        // narrows only the seniority field, not the whole block.
        $this->assertNotNull($res->json('employee_context.full_name'));
    }

    public function test_history_view_all_only_sees_conversation_but_not_employee_block(): void
    {
        ['card' => $card] = $this->makeCardWithEmployee();
        $admin = $this->adminWithRole('auditor'); // history.view_all, NOT escalation.work
        $token = $admin->createToken('t')->plainTextToken;

        $res = $this->withHeader('Authorization', "Bearer $token")->getJson("/admin/escalations/{$card->uuid}");
        $res->assertOk();

        // Pre-existing gate: unaffected by this correction — an auditor still
        // reads the conversation.
        $res->assertJsonPath('conversation_restricted', false);
        $this->assertNotEmpty($res->json('conversation'));

        // New gate: narrower than the conversation one, on purpose.
        $res->assertJsonPath('employee_context_restricted', true);
        $this->assertNull($res->json('employee_context'));
    }

    public function test_knowledge_editor_sees_neither(): void
    {
        ['card' => $card] = $this->makeCardWithEmployee();
        $admin = $this->adminWithRole('knowledge_editor'); // neither ability
        $token = $admin->createToken('t')->plainTextToken;

        $res = $this->withHeader('Authorization', "Bearer $token")->getJson("/admin/escalations/{$card->uuid}");
        $res->assertOk();
        $res->assertJsonPath('conversation_restricted', true);
        $res->assertJsonPath('employee_context_restricted', true);
        $this->assertNull($res->json('employee_context'));
    }

    /**
     * Server-side proof of "detail modal only" — the board LIST endpoint's
     * card shape never carries this block, so it cannot leak onto the board's
     * list-view cards no matter what the frontend does with the response.
     */
    public function test_board_list_endpoint_never_carries_the_employee_block(): void
    {
        $this->makeCardWithEmployee();
        $admin = $this->adminWithRole('hr_agent');
        $token = $admin->createToken('t')->plainTextToken;

        $res = $this->withHeader('Authorization', "Bearer $token")->getJson('/admin/escalations');
        $res->assertOk();
        $cards = $res->json('cards');
        $this->assertNotEmpty($cards);
        foreach ($cards as $card) {
            $this->assertArrayNotHasKey('employee_context', $card);
            $this->assertArrayNotHasKey('email', $card['employee'] ?? []);
        }
    }
}
