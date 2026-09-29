<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\GuardrailConfig;
use App\Models\GuardrailConfigEvent;
use App\Services\GuardrailPolicy;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Sprint 13, build step 9 (plan.md §B.6.6) — the Guardarraíles admin toggle
 * for the `general_knowledge` lane. Same ability-matrix/audit posture as
 * every other guardrail knob (`Sprint6GuardrailInvariantTest`), plus the
 * ONE thing genuinely new here: this knob is RESTRICT-only (AND with the env
 * baseline), the mirror image of the threshold knobs' RAISE-only (max).
 */
class Sprint13GeneralLaneToggleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        GuardrailPolicy::flush();
    }

    private function adminWithRole(string $role): Admin
    {
        $admin = Admin::create(['email' => $role.'-'.uniqid().'@example.com', 'full_name' => ucfirst($role), 'status' => 'active']);
        $admin->assignRole($role);

        return $admin;
    }

    private function reset(): void
    {
        $this->app['auth']->forgetGuards();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** @return array<string,string> */
    private function auth(Admin $admin): array
    {
        return ['Authorization' => 'Bearer '.$admin->createToken('t')->plainTextToken, 'Accept' => 'application/json'];
    }

    private function postGuardrails(Admin $admin, array $body): TestResponse
    {
        $this->reset();

        return $this->postJson('/admin/guardrails', $body, $this->auth($admin));
    }

    private function getGuardrails(Admin $admin): TestResponse
    {
        $this->reset();

        return $this->getJson('/admin/guardrails', $this->auth($admin));
    }

    // ---- 1. Ability matrix: only guardrails.manage may write --------------

    public function test_only_guardrails_manage_can_write_the_toggle(): void
    {
        $this->postGuardrails($this->adminWithRole('auditor'), ['general_lane_enabled' => false])->assertStatus(403);
        $this->postGuardrails($this->adminWithRole('hr_agent'), ['general_lane_enabled' => false])->assertStatus(403);
        $this->postGuardrails($this->adminWithRole('knowledge_editor'), ['general_lane_enabled' => false])->assertStatus(403);
        $this->postGuardrails($this->adminWithRole('super_admin'), ['general_lane_enabled' => false])->assertStatus(200);

        // READ is open to every admin (same oversight posture as every other knob).
        $this->getGuardrails($this->adminWithRole('auditor'))->assertStatus(200)->assertJsonStructure(['general_lane' => ['admin', 'env_baseline', 'effective']]);
    }

    // ---- 2. Effective = env baseline AND (admin ?? true) ------------------

    public function test_effective_value_is_env_baseline_and_admin(): void
    {
        // Env baseline OFF (test default, config/hr.php: HR_GENERAL_LANE_ENABLED
        // defaults false) — effective is false regardless of the admin value.
        config(['hr.general_lane.enabled' => false]);
        GuardrailPolicy::flush();
        $this->assertFalse((new GuardrailPolicy)->generalLaneEnabled());

        $super = $this->adminWithRole('super_admin');
        $this->postGuardrails($super, ['general_lane_enabled' => true])->assertStatus(200);
        GuardrailPolicy::flush();
        $this->assertFalse(
            (new GuardrailPolicy)->generalLaneEnabled(),
            'admin=true can never turn the lane on when the env baseline is off — restrict-only, not raise-only',
        );

        // Env baseline ON — admin null (unset) defaults to true (effective on).
        config(['hr.general_lane.enabled' => true]);
        GuardrailPolicy::flush();
        $this->postGuardrails($super, ['general_lane_enabled' => null])->assertStatus(200);
        GuardrailPolicy::flush();
        $this->assertTrue((new GuardrailPolicy)->generalLaneEnabled());

        // Env baseline ON — admin explicitly false narrows it off.
        $this->postGuardrails($super, ['general_lane_enabled' => false])->assertStatus(200);
        GuardrailPolicy::flush();
        $this->assertFalse((new GuardrailPolicy)->generalLaneEnabled());
    }

    public function test_effective_value_in_the_console_response_matches_the_policy(): void
    {
        config(['hr.general_lane.enabled' => true]);
        GuardrailPolicy::flush();
        $super = $this->adminWithRole('super_admin');
        $this->postGuardrails($super, ['general_lane_enabled' => false])->assertStatus(200);

        $body = $this->getGuardrails($super)->json('general_lane');
        $this->assertFalse($body['admin']);
        $this->assertTrue($body['env_baseline']);
        $this->assertFalse($body['effective']);
    }

    // ---- 3. Every write is audited -----------------------------------------

    public function test_every_write_is_audited(): void
    {
        $super = $this->adminWithRole('super_admin');
        $this->assertDatabaseCount('guardrail_config_events', 0);

        $this->postGuardrails($super, ['general_lane_enabled' => false])->assertStatus(200);
        $this->assertDatabaseHas('guardrail_config_events', ['field' => 'general_lane_enabled', 'new_value' => 'false']);
        $this->assertSame(false, GuardrailConfig::current()->general_lane_enabled);

        $this->postGuardrails($super, ['general_lane_enabled' => true])->assertStatus(200);
        $this->assertDatabaseHas('guardrail_config_events', ['field' => 'general_lane_enabled', 'old_value' => 'false', 'new_value' => 'true']);

        // A no-op write (same value) audits nothing new.
        $countBefore = GuardrailConfigEvent::count();
        $this->postGuardrails($super, ['general_lane_enabled' => true])->assertStatus(200);
        $this->assertSame($countBefore, GuardrailConfigEvent::count(), 'writing the same value again must not create a new audit row');
    }
}
