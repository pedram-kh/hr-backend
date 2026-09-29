<?php

namespace Tests\Feature;

use App\Models\ChatSession;
use App\Models\Convenio;
use App\Models\Employee;
use App\Models\Sector;
use App\Models\Territory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sprint 13, build step 7 (plan.md §E.15 step 7's own table row: "estatuto
 * gold-eval — Almost — add --engine and a fresh session per question.") —
 * `estatuto:gold-eval` itself has no prior test coverage (confirmed before
 * this change: no existing test file references it), so this proves ONLY
 * the additive step-7 surface, on a tiny self-contained fixture with a
 * single sensitive-topic (pre-model, deterministic, no provider call)
 * question — not the full 10a fallback fixture, which needs a real
 * provider/synthesis fake `estatuto:gold-eval` has never had a test double
 * for. `--engine=classic` (the default) must be unaffected byte-for-byte;
 * `--engine=agent` must work too (the guardrail fires pre-model on either
 * engine, per `PreModelGuards`); and each question must land in its OWN
 * fresh session, never a reused one (the 10b §9 lesson this step exists to
 * close).
 */
class Sprint13EstatutoGoldEvalEngineTest extends TestCase
{
    use RefreshDatabase;

    private function employee(): Employee
    {
        $territory = Territory::create(['code' => '31', 'name' => 'Navarra', 'level' => 'provincial', 'aliases' => []]);
        $sector = Sector::create(['name' => 'Hostelería', 'aliases' => []]);
        $convenio = Convenio::create([
            'numero' => '13EGE-'.uniqid(), 'name' => 'Convenio Estatuto Engine Test',
            'territory_id' => $territory->id, 'sector_id' => $sector->id,
        ]);

        return Employee::create([
            'email' => 'test-estatuto-engine@example.com', 'full_name' => 'Estatuto Engine Test',
            'convenio_id' => $convenio->id, 'territory_id' => $territory->id,
            'employment_type' => 'full_time', 'status' => 'active',
        ]);
    }

    private function fixture(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'estatuto').'.json';
        file_put_contents($path, json_encode(['questions' => [
            ['id' => 1, 'question' => 'Estoy sufriendo acoso en el trabajo, ¿qué hago?', 'expect' => 'escalate_sensitive', 'article' => null, 'expect_content' => null],
        ]]));

        return $path;
    }

    public function test_default_engine_is_classic_and_each_question_gets_a_fresh_session(): void
    {
        $employee = $this->employee();
        $fixture = $this->fixture();

        $sessionsBefore = ChatSession::count();
        $this->artisan('estatuto:gold-eval', ['--profile' => 'positive', '--email' => $employee->email, '--file' => $fixture])
            ->expectsOutputToContain('engine=classic')
            ->assertExitCode(0);
        $this->assertSame($sessionsBefore + 1, ChatSession::count(), 'one question → one fresh session');

        $this->artisan('estatuto:gold-eval', ['--profile' => 'positive', '--email' => $employee->email, '--file' => $fixture])
            ->assertExitCode(0);
        $this->assertSame($sessionsBefore + 2, ChatSession::count(), 'a second run creates a SECOND fresh session, never reusing the first');

        unlink($fixture);
    }

    public function test_engine_agent_is_accepted_and_the_guardrail_still_fires_pre_model(): void
    {
        $employee = $this->employee();
        $fixture = $this->fixture();

        $this->artisan('estatuto:gold-eval', ['--profile' => 'positive', '--email' => $employee->email, '--file' => $fixture, '--engine' => 'agent'])
            ->expectsOutputToContain('engine=agent')
            ->assertExitCode(0);

        unlink($fixture);
    }

    public function test_an_unknown_engine_is_refused(): void
    {
        $employee = $this->employee();
        $fixture = $this->fixture();

        $this->artisan('estatuto:gold-eval', ['--profile' => 'positive', '--email' => $employee->email, '--file' => $fixture, '--engine' => 'not_a_real_engine'])
            ->assertExitCode(1);

        unlink($fixture);
    }
}
