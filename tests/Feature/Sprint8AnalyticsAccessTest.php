<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\Convenio;
use App\Models\Employee;
use App\Models\QualitySample;
use App\Models\Sector;
use App\Models\Territory;
use App\Services\IdentityPresenter;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sprint 8 (plan.md §9, §11) — the 4-role × 3-screen access matrix, hit
 * DIRECTLY via API (mirrors `Sprint5AccessMatrixTest`/`Sprint6…` precedent
 * exactly, not asserted through the UI):
 *
 * | screen                    | super_admin | hr_agent | knowledge_editor | auditor |
 * |---------------------------|-------------|----------|------------------|---------|
 * | Analítica (analytics.view)|     ✅      |    ✅    |        ❌        | ✅ (ro) |
 * | Cobertura (…OR knowledge.edit)| ✅      |    ✅    |    ✅ (coverage) | ✅ (ro) |
 * | Calidad reads (no ability — open to any admin) | ✅ | ✅ | ✅ | ✅ |
 * | Calidad review write (escalation.work) |  ✅  |    ✅    |        ❌        |   ❌    |
 *
 * Found live, eyes-on 2026-09-10: the route-level middleware checks below
 * were always correct, but `IdentityPresenter::present()` (the payload the
 * FRONTEND reads to decide which nav entries to render) never carried
 * `analytics.view` at all — so no role's nav ever showed Analítica, and
 * Cobertura's nav only survived for roles that also happen to hold
 * `knowledge.edit` (super_admin), by the OR-fallback coincidence, not
 * because analytics.view worked. `test_identity_payload_carries_analytics_
 * view_correctly` below is the regression test for that specific bug — a
 * route-hit test alone can never catch it, since the route's own
 * `ability:analytics.view` middleware doesn't go through this presenter at
 * all.
 */
class Sprint8AnalyticsAccessTest extends TestCase
{
    use RefreshDatabase;

    private QualitySample $sample;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->buildWorld();
    }

    private function buildWorld(): void
    {
        $territory = Territory::create(['code' => '01', 'name' => 'Álava', 'level' => 'provincial', 'aliases' => []]);
        $sector = Sector::create(['name' => 'Test Sector', 'aliases' => []]);
        $convenio = Convenio::create(['numero' => '01TESTM001', 'name' => 'Test Convenio', 'territory_id' => $territory->id, 'sector_id' => $sector->id]);
        $employee = Employee::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(), 'email' => 'm@example.com', 'full_name' => 'Matrix Worker',
            'convenio_id' => $convenio->id, 'territory_id' => $territory->id, 'employment_type' => 'full_time', 'status' => 'active',
        ]);
        $session = ChatSession::create(['employee_id' => $employee->id, 'started_at' => now(), 'last_activity_at' => now()]);
        ChatMessage::create(['session_id' => $session->id, 'role' => 'user', 'content' => 'pregunta de prueba']);
        $assistantMessage = ChatMessage::create(['session_id' => $session->id, 'role' => 'assistant', 'content' => 'respuesta de prueba']);

        $this->sample = QualitySample::create([
            'message_id' => $assistantMessage->id, 'sampled_for_month' => '2026-09', 'seed' => 1,
            'stratum_path' => 'prose', 'stratum_territory_id' => $territory->id,
        ]);
    }

    private function adminWithRole(string $role): Admin
    {
        $admin = Admin::create(['email' => $role.'-'.uniqid().'@example.com', 'full_name' => ucfirst($role), 'status' => 'active']);
        $admin->assignRole($role);

        return $admin;
    }

    private function auth(Admin $admin): array
    {
        return ['Authorization' => 'Bearer '.$admin->createToken('test')->plainTextToken, 'Accept' => 'application/json'];
    }

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

    private function postAs(Admin $admin, string $url, array $payload = []): \Illuminate\Testing\TestResponse
    {
        $this->resetPermCache();

        return $this->postJson($url, $payload, $this->auth($admin));
    }

    // ---- Analítica: analytics.view (super_admin/hr_agent/auditor); knowledge_editor denied ----

    public function test_analytics_view_matrix(): void
    {
        $this->getAs($this->adminWithRole('super_admin'), '/admin/analytics/deflection')->assertStatus(200);
        $this->getAs($this->adminWithRole('hr_agent'), '/admin/analytics/deflection')->assertStatus(200);
        $this->getAs($this->adminWithRole('auditor'), '/admin/analytics/deflection')->assertStatus(200);
        $this->getAs($this->adminWithRole('knowledge_editor'), '/admin/analytics/deflection')->assertStatus(403);

        $this->getAs($this->adminWithRole('knowledge_editor'), '/admin/analytics/escalations-by-fix')->assertStatus(403);
        $this->getAs($this->adminWithRole('knowledge_editor'), '/admin/analytics/clusters')->assertStatus(403);
    }

    // ---- Cobertura: analytics.view OR knowledge.edit — every role reaches it ----

    public function test_coverage_view_matrix(): void
    {
        $this->getAs($this->adminWithRole('super_admin'), '/admin/coverage/gaps')->assertStatus(200);
        $this->getAs($this->adminWithRole('hr_agent'), '/admin/coverage/gaps')->assertStatus(200);
        $this->getAs($this->adminWithRole('auditor'), '/admin/coverage/gaps')->assertStatus(200);
        // knowledge_editor has NEITHER analytics.view NOR history.view_all, but
        // DOES have knowledge.edit — the one place this sprint's OR-gate matters.
        $this->getAs($this->adminWithRole('knowledge_editor'), '/admin/coverage/gaps')->assertStatus(200);
    }

    public function test_coverage_export_and_trend_follow_the_same_gate(): void
    {
        $this->getAs($this->adminWithRole('knowledge_editor'), '/admin/coverage/export')->assertStatus(200);
        $this->getAs($this->adminWithRole('knowledge_editor'), '/admin/coverage/trend')->assertStatus(200);
    }

    /** An admin with NONE of the three abilities (a role-less/edge case) is denied. */
    public function test_coverage_denied_without_either_ability(): void
    {
        $admin = Admin::create(['email' => 'bare@example.com', 'full_name' => 'Bare Admin', 'status' => 'active']);
        // Deliberately no role assigned — no abilities at all.
        $this->getAs($admin, '/admin/coverage/gaps')->assertStatus(403);
    }

    // ---- Calidad: reads open to any admin; the review WRITE needs escalation.work ----

    public function test_quality_sample_reads_are_open_to_any_admin(): void
    {
        $this->getAs($this->adminWithRole('super_admin'), '/admin/quality-samples')->assertStatus(200);
        $this->getAs($this->adminWithRole('hr_agent'), '/admin/quality-samples')->assertStatus(200);
        $this->getAs($this->adminWithRole('auditor'), '/admin/quality-samples')->assertStatus(200);
        $this->getAs($this->adminWithRole('knowledge_editor'), '/admin/quality-samples')->assertStatus(200);
    }

    /**
     * Regression for the eyes-on-found bug (2026-09-10): `IdentityPresenter`
     * — the payload the frontend's `canViewAnalytics`/`canViewCoverage`
     * read — must actually carry `analytics.view`, matching the real Spatie
     * grant, for every role. A route-hit test (like the ones above) cannot
     * catch this: the route middleware checks the ability directly, never
     * through this presenter.
     */
    public function test_identity_payload_carries_analytics_view_correctly(): void
    {
        $superAdmin = $this->adminWithRole('super_admin');
        $hrAgent = $this->adminWithRole('hr_agent');
        $auditor = $this->adminWithRole('auditor');
        $knowledgeEditor = $this->adminWithRole('knowledge_editor');

        $this->resetPermCache();
        $this->assertTrue(IdentityPresenter::present($superAdmin->fresh(), 'admin')['abilities']['analytics.view']);
        $this->assertTrue(IdentityPresenter::present($hrAgent->fresh(), 'admin')['abilities']['analytics.view']);
        $this->assertTrue(IdentityPresenter::present($auditor->fresh(), 'admin')['abilities']['analytics.view']);
        $this->assertFalse(IdentityPresenter::present($knowledgeEditor->fresh(), 'admin')['abilities']['analytics.view']);

        // Sanity: super_admin sees all three real screens' underlying data —
        // this is the concrete "super_admin sees all three" the eyes-on asked for.
        $this->getAs($superAdmin, '/admin/analytics/deflection')->assertStatus(200);
        $this->getAs($superAdmin, '/admin/coverage/gaps')->assertStatus(200);
        $this->getAs($superAdmin, '/admin/quality-samples')->assertStatus(200);
    }

    public function test_quality_sample_review_write_matrix(): void
    {
        $this->postAs($this->adminWithRole('knowledge_editor'), "/admin/quality-samples/{$this->sample->uuid}/review", ['verdict' => 'correct'])
            ->assertStatus(403);
        $this->postAs($this->adminWithRole('auditor'), "/admin/quality-samples/{$this->sample->uuid}/review", ['verdict' => 'correct'])
            ->assertStatus(403);

        $hr = $this->adminWithRole('hr_agent');
        $this->postAs($hr, "/admin/quality-samples/{$this->sample->uuid}/review", ['verdict' => 'correct'])
            ->assertStatus(200)
            ->assertJsonPath('sample.verdict', 'correct');
    }

    public function test_super_admin_review_write_opens_a_card_on_wrong(): void
    {
        $super = $this->adminWithRole('super_admin');

        $this->postAs($super, "/admin/quality-samples/{$this->sample->uuid}/review", [
            'verdict' => 'wrong',
            'failure_kind' => 'unclear',
            'note' => 'the phrasing confused the employee',
        ])->assertStatus(200)
            ->assertJsonPath('sample.verdict', 'wrong')
            ->assertJsonStructure(['sample' => ['escalation_card_id']]);

        $this->assertDatabaseHas('escalation_cards', ['reason' => 'quality_sample_wrong']);
    }
}
