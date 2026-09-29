<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AnswerEngineSetting;
use App\Models\Convenio;
use App\Models\Employee;
use App\Models\Sector;
use App\Models\Territory;
use App\Services\AnswerEngineDispatcher;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Sprint 13, build step 2 (plan.md §E.15 step 2) — proves the ONE claim step
 * 2 makes: "switch flip changes nothing; access matrix unchanged." The agent
 * branch has no shell yet (step 3), so it delegates to classic verbatim —
 * flipping the runtime override must not change a single byte of the
 * response, and the endpoint's employee-only access boundary must not care
 * which engine serves the turn.
 */
class Sprint13AnswerEngineDispatcherTest extends TestCase
{
    use RefreshDatabase;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        $territory = Territory::create(['code' => '01', 'name' => 'Álava', 'level' => 'provincial', 'aliases' => []]);
        $sector = Sector::create(['name' => 'Sector', 'aliases' => []]);
        $convenio = Convenio::create(['numero' => '13ENGX0001', 'name' => 'Engine Switch Test', 'territory_id' => $territory->id, 'sector_id' => $sector->id]);
        $this->employee = Employee::create([
            'email' => 'engine-switch@example.com', 'full_name' => 'Engine Switch',
            'convenio_id' => $convenio->id, 'territory_id' => $territory->id,
            'employment_type' => 'full_time', 'status' => 'active',
        ]);
    }

    private function adminWithRole(string $role): Admin
    {
        $admin = Admin::create(['email' => $role.'-'.uniqid().'@example.com', 'full_name' => ucfirst($role), 'status' => 'active']);
        $admin->assignRole($role);

        return $admin;
    }

    /** Deterministic, no LLM/DB dependency: R01 fires pre-model on any engine. */
    private function askSensitive(): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $token = $this->employee->createToken('emp')->plainTextToken;

        return $this->postJson('/chat/message', ['question' => 'Estoy sufriendo acoso en el trabajo, ¿qué hago?'], [
            'Authorization' => 'Bearer '.$token, 'Accept' => 'application/json',
        ]);
    }

    public function test_default_effective_engine_is_classic(): void
    {
        $dispatcher = app(AnswerEngineDispatcher::class);
        $this->assertSame('classic', $dispatcher->effectiveEngine());
    }

    public function test_db_override_takes_precedence_over_env_baseline(): void
    {
        AnswerEngineSetting::current()->setEngine('agent');
        $dispatcher = app(AnswerEngineDispatcher::class);
        $this->assertSame('agent', $dispatcher->effectiveEngine());
    }

    public function test_invalid_db_override_falls_back_to_env_baseline(): void
    {
        $row = AnswerEngineSetting::current();
        $row->engine = 'not_a_real_engine';
        $row->save();

        $this->assertSame('classic', app(AnswerEngineDispatcher::class)->effectiveEngine());
    }

    /**
     * The load-bearing proof: flipping the runtime override to `agent`
     * produces a BYTE-IDENTICAL response (minus the always-random
     * session/message identifiers), because step 2's agent branch has no
     * shell of its own yet and delegates to classic.
     */
    public function test_switch_flip_changes_nothing(): void
    {
        $classicResponse = $this->askSensitive()->json();

        AnswerEngineSetting::current()->setEngine('agent');
        $agentResponse = $this->askSensitive()->json();

        $classicStable = $classicResponse;
        $agentStable = $agentResponse;
        unset($classicStable['session_uuid'], $classicStable['message_id'], $classicStable['escalation_uuid']);
        unset($agentStable['session_uuid'], $agentStable['message_id'], $agentStable['escalation_uuid']);

        $this->assertSame($classicStable, $agentStable);
        $this->assertTrue($classicResponse['escalated']);
        $this->assertSame('sensitive_topic', $classicResponse['escalation_reason']);
    }

    public function test_access_matrix_unchanged_by_engine(): void
    {
        AnswerEngineSetting::current()->setEngine('agent');
        $admin = $this->adminWithRole('super_admin');
        $token = $admin->createToken('t')->plainTextToken;

        $response = $this->postJson('/chat/message', ['question' => 'hola'], [
            'Authorization' => 'Bearer '.$token, 'Accept' => 'application/json',
        ]);

        $response->assertStatus(403);
    }

    // ---- answer-engine:set command ------------------------------------

    public function test_command_requires_admin_option(): void
    {
        $this->artisan('answer-engine:set', ['engine' => 'agent'])
            ->assertExitCode(1);
        $this->assertNull(AnswerEngineSetting::current()->engine);
    }

    public function test_command_refuses_unknown_admin_email(): void
    {
        $this->artisan('answer-engine:set', ['engine' => 'agent', '--admin' => 'nope@example.com'])
            ->assertExitCode(1);
        $this->assertNull(AnswerEngineSetting::current()->engine);
    }

    public function test_command_refuses_non_super_admin(): void
    {
        $admin = $this->adminWithRole('knowledge_editor');
        $this->artisan('answer-engine:set', ['engine' => 'agent', '--admin' => $admin->email])
            ->assertExitCode(1);
        $this->assertNull(AnswerEngineSetting::current()->engine);
    }

    public function test_command_sets_and_clears_override_for_super_admin(): void
    {
        $admin = $this->adminWithRole('super_admin');

        $this->artisan('answer-engine:set', ['engine' => 'agent', '--admin' => $admin->email])
            ->assertExitCode(0);
        $row = AnswerEngineSetting::current();
        $this->assertSame('agent', $row->engine);
        $this->assertSame($admin->id, $row->updated_by);

        $this->artisan('answer-engine:set', ['--clear' => true, '--admin' => $admin->email])
            ->assertExitCode(0);
        $this->assertNull(AnswerEngineSetting::current()->refresh()->engine);
    }

    public function test_command_rejects_invalid_engine_name(): void
    {
        $admin = $this->adminWithRole('super_admin');
        $this->artisan('answer-engine:set', ['engine' => 'bogus', '--admin' => $admin->email])
            ->assertExitCode(1);
        $this->assertNull(AnswerEngineSetting::current()->engine);
    }
}
