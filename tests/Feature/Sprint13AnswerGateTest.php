<?php

namespace Tests\Feature;

use App\Models\ChatMessage;
use App\Models\Convenio;
use App\Models\ConvenioJobCategory;
use App\Models\Employee;
use App\Models\SalaryTable;
use App\Models\SalaryTableRow;
use App\Models\Sector;
use App\Models\Territory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Sprint 13, build step 7 (plan.md §E.14/§E.15 step 7) — `answer:gate`
 * mechanics on small local fixtures, no live provider: pass-rate scoring,
 * the hard false-answer-on-must-escalate gate, the persist/rollback split
 *
 * (test-*@example.com only, mirroring `EstatutoGoldEval`), `--engine=both`
 * + `--repeat` multiplying rows, and a scope-based case (the
 * `fact-routing.json` convention) reporting "not found" rather than
 * fabricating a convenio. Every case here is deterministic (pre-model
 * guardrail / salary SQL) — no `PlannerClient`/hr-ai fake is needed because
 * `--engine=classic` never reaches the planner.
 */
class Sprint13AnswerGateTest extends TestCase
{
    use RefreshDatabase;

    private Territory $territory;

    private Sector $sector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->territory = Territory::create(['code' => '31', 'name' => 'Navarra', 'level' => 'provincial', 'aliases' => []]);
        $this->sector = Sector::create(['name' => 'Hostelería', 'aliases' => []]);
    }

    private function convenio(string $numero): Convenio
    {
        return Convenio::create([
            'numero' => '13GATE-'.$numero, 'name' => 'Convenio Gate '.$numero,
            'territory_id' => $this->territory->id, 'sector_id' => $this->sector->id,
        ]);
    }

    private function fixture(array $cases): string
    {
        $path = tempnam(sys_get_temp_dir(), 'gate').'.json';
        file_put_contents($path, json_encode(['cases' => $cases], JSON_UNESCAPED_UNICODE));

        return $path;
    }

    public function test_a_deterministic_escalation_case_passes_and_reports_pass_rate(): void
    {
        $convenio = $this->convenio('sens');
        $employee = Employee::create([
            'email' => 'gate-sens@example.com', 'full_name' => 'Gate Test',
            'convenio_id' => $convenio->id, 'territory_id' => $this->territory->id,
            'employment_type' => 'full_time', 'status' => 'active',
        ]);

        $fixture = $this->fixture([[
            'id' => 'sensitive',
            'email' => $employee->email,
            'question' => 'Estoy sufriendo acoso en el trabajo, ¿qué hago?',
            'expect' => ['outcome' => 'escalate', 'reason' => 'sensitive_topic'],
        ]]);

        $this->artisan('answer:gate', ['--engine' => 'classic', '--set' => $fixture])
            ->expectsOutputToContain('pass rate: 1/1')
            ->expectsOutputToContain('false answers on must-escalate cases (hard, must be 0): 0')
            ->assertExitCode(0);

        unlink($fixture);
    }

    /** @return array{employee: Employee} a category + salary table so classic answers a plain salary question. */
    private function employeeWithSalary(): Employee
    {
        $convenio = $this->convenio('sal');
        $category = ConvenioJobCategory::create(['convenio_id' => $convenio->id, 'name' => 'Camarero/a']);
        $employee = Employee::create([
            'email' => 'test-gate-sal@example.com', 'full_name' => 'Gate Salary Test',
            'convenio_id' => $convenio->id, 'job_category_id' => $category->id,
            'territory_id' => $this->territory->id, 'employment_type' => 'full_time', 'status' => 'active',
        ]);
        $table = SalaryTable::create(['convenio_id' => $convenio->id, 'year' => now()->year]);
        SalaryTableRow::create([
            'salary_table_id' => $table->id, 'job_category_id' => $category->id,
            'gross_annual' => 20000, 'base_salary_monthly' => 1428.57, 'hourly_rate' => 12.5,
        ]);

        return $employee;
    }

    public function test_a_false_answer_on_a_must_escalate_case_is_a_hard_failure(): void
    {
        $employee = $this->employeeWithSalary();

        // Deliberately wrong expectation (`must_not_answer`), to prove the
        // hard gate actually fires when a case answers that it should not.
        $fixture = $this->fixture([[
            'id' => 'salary-should-not-answer',
            'email' => $employee->email,
            'question' => '¿Cuánto cobro?',
            'expect' => ['must_not_answer' => true],
        ]]);

        $this->artisan('answer:gate', ['--engine' => 'classic', '--set' => $fixture])
            ->expectsOutputToContain('FALSE ANSWER ON MUST-ESCALATE CASE')
            ->expectsOutputToContain('false answers on must-escalate cases (hard, must be 0): 1')
            ->assertExitCode(1);

        unlink($fixture);
    }

    public function test_forbid_paths_and_any_of_and_the_summary_block(): void
    {
        $employee = $this->employeeWithSalary();

        // The classic salary answer's path is not `general_knowledge`, so forbid_paths
        // holds; any_of accepts EITHER alternative; `class` groups the report.
        $fixture = $this->fixture([[
            'id' => 'salary-any-of',
            'class' => 'unit',
            'email' => $employee->email,
            'question' => '¿Cuánto cobro?',
            'expect' => [
                'forbid_paths' => ['general_knowledge'],
                'any_of' => [['outcome' => 'escalate', 'reason' => 'nope'], ['outcome' => 'answer']],
            ],
        ]]);

        $code = Artisan::call('answer:gate', ['--engine' => 'classic', '--set' => $fixture, '--repeat' => 2, '--json' => true]);
        $out = Artisan::output();
        $json = json_decode($out, true);

        $this->assertSame(0, $code);
        $this->assertSame(0, $json['summary']['engines']['classic']['lane_answers']);
        $this->assertSame(2, $json['summary']['engines']['classic']['by_class']['unit']['n']);
        $this->assertArrayHasKey('p50', $json['summary']['engines']['classic']['latency_ms']);
        $this->assertSame(2, $json['summary']['cases'][0]['classic']['n']);

        unlink($fixture);
    }

    public function test_filter_class_and_stream_options(): void
    {
        $employee = $this->employeeWithSalary();

        $fixture = $this->fixture([
            ['id' => 'keep-me', 'class' => 'a', 'email' => $employee->email, 'question' => '¿Cuánto cobro?', 'expect' => ['outcome' => 'answer']],
            ['id' => 'skip-me', 'class' => 'b', 'email' => $employee->email, 'question' => '¿Cuánto cobro?', 'expect' => ['outcome' => 'answer']],
        ]);
        $stream = sys_get_temp_dir().'/gate-stream-'.uniqid().'.jsonl';

        $this->artisan('answer:gate', ['--engine' => 'classic', '--set' => $fixture, '--filter' => '^keep', '--stream' => $stream])->assertExitCode(0);
        $lines = array_filter(explode("\n", (string) file_get_contents($stream)));
        $this->assertCount(1, $lines);
        $this->assertSame('keep-me', json_decode(array_values($lines)[0], true)['id']);

        unlink($stream);
        $this->artisan('answer:gate', ['--engine' => 'classic', '--set' => $fixture, '--class' => 'b', '--stream' => $stream])->assertExitCode(0);
        $this->assertSame('skip-me', json_decode(trim((string) file_get_contents($stream)), true)['id']);

        unlink($stream);
        unlink($fixture);
    }

    public function test_caveat_any_gates_an_answer_but_lets_an_escalation_through(): void
    {
        $employee = $this->employeeWithSalary();

        $fixture = $this->fixture([
            ['id' => 'caveat-missing', 'email' => $employee->email, 'question' => '¿Cuánto cobro?', 'expect' => ['outcome_in' => ['answer', 'escalate'], 'caveat_any' => ['zzz-never-in-an-answer']]],
            ['id' => 'caveat-present', 'email' => $employee->email, 'question' => '¿Cuánto cobro?', 'expect' => ['outcome_in' => ['answer', 'escalate'], 'caveat_any' => ['']]],
        ]);

        Artisan::call('answer:gate', ['--engine' => 'classic', '--set' => $fixture, '--json' => true]);
        $rows = collect(json_decode(Artisan::output(), true)['rows'])->keyBy('id');

        $this->assertSame('answer', $rows['caveat-missing']['outcome']);
        $this->assertFalse($rows['caveat-missing']['caveat_ok']);
        $this->assertFalse($rows['caveat-missing']['pass']);
        $this->assertTrue($rows['caveat-present']['caveat_ok']);
        $this->assertTrue($rows['caveat-present']['pass']);
        $this->assertNotSame('', $rows['caveat-present']['answer_excerpt']);

        unlink($fixture);
    }

    public function test_any_of_fails_when_no_alternative_holds(): void
    {
        $employee = $this->employeeWithSalary();

        $fixture = $this->fixture([[
            'id' => 'salary-any-of-miss',
            'email' => $employee->email,
            'question' => '¿Cuánto cobro?',
            'expect' => ['any_of' => [['outcome' => 'escalate'], ['reason' => 'low_confidence']]],
        ]]);

        $this->artisan('answer:gate', ['--engine' => 'classic', '--set' => $fixture])
            ->expectsOutputToContain('pass rate: 0/1')
            ->assertExitCode(1);

        unlink($fixture);
    }

    public function test_default_no_writes_then_persist_only_for_test_accounts(): void
    {
        $employee = $this->employeeWithSalary(); // gate-sal@example.com — a real test-*@example.com shape

        $fixture = $this->fixture([[
            'id' => 'salary-ok',
            'email' => $employee->email,
            'question' => '¿Cuánto cobro?',
            'expect' => ['outcome' => 'answer'],
        ]]);

        $before = ChatMessage::count();
        $this->artisan('answer:gate', ['--engine' => 'classic', '--set' => $fixture])->assertExitCode(0);
        $this->assertSame($before, ChatMessage::count(), 'no --persist: the transaction must roll back, leaving no messages');

        $this->artisan('answer:gate', ['--engine' => 'classic', '--set' => $fixture, '--persist' => true])->assertExitCode(0);
        $this->assertSame($before + 2, ChatMessage::count(), '--persist on a test-*@example.com employee: one user + one assistant message committed');

        unlink($fixture);
    }

    public function test_persist_is_refused_for_a_non_test_account_even_when_passed(): void
    {
        $convenio = $this->convenio('real');
        $employee = Employee::create([
            'email' => 'real.person@empresa.com', 'full_name' => 'Real Employee',
            'convenio_id' => $convenio->id, 'territory_id' => $this->territory->id,
            'employment_type' => 'full_time', 'status' => 'active',
        ]);

        $fixture = $this->fixture([[
            'id' => 'sensitive-real',
            'email' => $employee->email,
            'question' => 'Estoy sufriendo acoso en el trabajo, ¿qué hago?',
            'expect' => ['outcome' => 'escalate'],
        ]]);

        $before = ChatMessage::count();
        $this->artisan('answer:gate', ['--engine' => 'classic', '--set' => $fixture, '--persist' => true])->assertExitCode(0);
        $this->assertSame($before, ChatMessage::count(), 'a non test-*@example.com employee must never be persisted, even with --persist');

        unlink($fixture);
    }

    public function test_json_output_has_one_row_per_engine_per_repeat(): void
    {
        $convenio = $this->convenio('json');
        $employee = Employee::create([
            'email' => 'gate-json@example.com', 'full_name' => 'Gate Json Test',
            'convenio_id' => $convenio->id, 'territory_id' => $this->territory->id,
            'employment_type' => 'full_time', 'status' => 'active',
        ]);

        $fixture = $this->fixture([[
            'id' => 'sensitive-json',
            'email' => $employee->email,
            'question' => 'Estoy sufriendo acoso en el trabajo, ¿qué hago?',
            'expect' => ['outcome' => 'escalate', 'reason' => 'sensitive_topic'],
        ]]);

        $captured = null;
        Artisan::call('answer:gate', [
            '--engine' => 'both', '--set' => $fixture, '--repeat' => 2, '--json' => true,
        ]);
        $captured = Artisan::output();
        unlink($fixture);

        $decoded = json_decode($captured, true, 512, JSON_THROW_ON_ERROR);
        $this->assertCount(4, $decoded['rows'], 'engine=both x repeat=2 x 1 case = 4 rows');
        $this->assertSame(['classic', 'classic', 'agent', 'agent'], array_column($decoded['rows'], 'engine'));
    }

    public function test_a_scope_based_case_with_an_unknown_convenio_is_skipped_not_fabricated(): void
    {
        $fixture = $this->fixture([[
            'id' => 'unknown-scope',
            'convenio_id' => 999999999,
            'group_label' => 'Grupo 1',
            'as_of_date' => '2024-01-01',
            'canonical_question' => '¿Cuál es la duración de mi periodo de prueba?',
            'colloquial_question' => 'Acabo de empezar, ¿cuánto dura la prueba?',
            'expected_path' => ['reference_fact', 'reference_fact_composition'],
            'value_contains' => ['15 días'],
        ]]);

        Artisan::call('answer:gate', ['--engine' => 'classic', '--set' => $fixture]);
        $output = Artisan::output();
        unlink($fixture);

        $this->assertStringContainsString('SKIPPED', $output);
        $this->assertStringContainsString('scope not found', $output);
    }
}
