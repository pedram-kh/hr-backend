<?php

namespace Tests\Feature;

use App\Models\ChatSession;
use App\Models\Convenio;
use App\Models\Employee;
use App\Models\Sector;
use App\Models\Territory;
use App\Services\Agent\RuleEngine;
use App\Services\Agent\Rules\FigureNotFromTablePostCallRule;
use App\Services\Agent\Rules\SalaryIntentPreCallRule;
use App\Services\Agent\ToolResult;
use App\Services\Agent\TurnState;
use App\Services\Agent\Verdict;
use App\Services\Answer\TurnOutcome;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Sprint 13, CP-2 fix for whitelist case wt-03 ("Llevo cinco años en la empresa,
 * ¿cuántos trienios cobro?" — the agent answered from prose with a euro figure for a
 * category the employee does not have; classic escalated through salary_sql).
 *
 * (a) {@see SalaryIntentPreCallRule} — pay-intent questions may not use
 *     convenio_search / national_law.
 * (b) {@see FigureNotFromTablePostCallRule} — a euro amount in synthesised prose that
 *     salary_lookup did not produce this turn is escalated low_confidence /
 *     figure_not_from_table.
 * Both are agent-only; classic (and so the golden traces) never runs them.
 */
class Sprint13SalaryIntentGuardTest extends TestCase
{
    use RefreshDatabase;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $territory = Territory::create(['code' => '01', 'name' => 'Álava', 'level' => 'provincial', 'aliases' => []]);
        $sector = Sector::create(['name' => 'Sector', 'aliases' => []]);
        $convenio = Convenio::create(['numero' => '13SALINT01', 'name' => 'Salary Intent Test', 'territory_id' => $territory->id, 'sector_id' => $sector->id]);
        $this->employee = Employee::create([
            'email' => 'salary-intent@example.com', 'full_name' => 'Salary Intent',
            'convenio_id' => $convenio->id, 'territory_id' => $territory->id,
            'employment_type' => 'full_time', 'status' => 'active',
        ]);
    }

    private function state(string $question): TurnState
    {
        $session = ChatSession::create(['employee_id' => $this->employee->id, 'started_at' => now(), 'last_activity_at' => now()]);

        return new TurnState($this->employee, $question, Carbon::today(), $session, []);
    }

    /** @param  array<string,mixed>  $input */
    private function toolCall(string $tool = 'convenio_search', array $input = []): array
    {
        return ['id' => 'c1', 'tool' => $tool, 'input' => $input];
    }

    // ---- (a) pre-call --------------------------------------------------

    /** @return array<string,array{0:string}> */
    public static function payIntentQuestions(): array
    {
        return [
            'wt-03 verbatim' => ['Llevo cinco años en la empresa, ¿cuántos trienios cobro?'],
            'trienio' => ['¿Cuánto es un trienio en mi convenio?'],
            'quinquenio' => ['¿Cuánto se paga por quinquenio?'],
            'retribución' => ['¿Cuál es mi retribución por nocturnidad?'],
            'plus' => ['¿Qué plus de peligrosidad me corresponde?'],
            'complemento' => ['¿Hay algún complemento por turnos y cuánto es?'],
            'nómina' => ['¿Qué conceptos salen en la nómina?'],
            'salario' => ['¿Cuál es el salario base de mi categoría?'],
            'antigüedad pay' => ['¿Cuánto me pagan por antigüedad?'],
            'accented/uppercase' => ['¿CUÁL ES MI RETRIBUCIÓN?'],
        ];
    }

    #[DataProvider('payIntentQuestions')]
    public function test_pay_intent_question_may_not_use_prose_tools(string $question): void
    {
        $rule = $this->app->make(SalaryIntentPreCallRule::class);

        foreach (['convenio_search', 'national_law'] as $tool) {
            $verdict = $rule->evaluate($this->state($question), $this->toolCall($tool), null);
            $this->assertSame(Verdict::DENY, $verdict->status, "{$tool} must be denied for: {$question}");
            $this->assertSame('salary_intent_pre_call', $verdict->rule);
        }
    }

    /** @return array<string,array{0:string}> */
    public static function nonPayQuestions(): array
    {
        return [
            'vacaciones' => ['¿Cuántos días de vacaciones tengo?'],
            'periodo de prueba' => ['¿Cuánto dura el periodo de prueba?'],
            'antigüedad non-pay' => ['¿Cuenta la antigüedad para pedir una excedencia?'],
            'permiso' => ['¿Qué permiso me corresponde por matrimonio?'],
            'teletrabajo' => ['¿Puedo trabajar a distancia?'],
            'permisos retribuidos (paid leave, not pay)' => ['¿Qué permisos retribuidos me corresponden?'],
            'cobrar during sick leave (benefit, not pay table)' => ['¿Tengo derecho a cobrar durante la baja?'],
        ];
    }

    #[DataProvider('nonPayQuestions')]
    public function test_non_pay_question_is_not_touched(string $question): void
    {
        $rule = $this->app->make(SalaryIntentPreCallRule::class);
        $this->assertTrue($rule->evaluate($this->state($question), $this->toolCall(), null)->isAllow(), $question);
    }

    public function test_planner_declared_pay_intent_in_the_call_input_is_denied(): void
    {
        $rule = $this->app->make(SalaryIntentPreCallRule::class);
        $state = $this->state('¿Qué me corresponde por llevar cinco años?');

        $this->assertTrue($rule->evaluate($state, $this->toolCall(), null)->isAllow(), 'no pay intent anywhere → allowed');

        $verdict = $rule->evaluate($state, $this->toolCall('convenio_search', ['subqueries' => ['importe del trienio por categoría']]), null);
        $this->assertSame(Verdict::DENY, $verdict->status);

        $verdict = $rule->evaluate($state, $this->toolCall('convenio_search', ['decomposed_queries' => ['complemento de antigüedad tabla salarial']]), null);
        $this->assertSame(Verdict::DENY, $verdict->status);
    }

    public function test_compound_question_with_a_non_pay_half_keeps_prose_retrieval(): void
    {
        $rule = $this->app->make(SalaryIntentPreCallRule::class);
        $verdict = $rule->evaluate($this->state('¿Cuánto cobro de plus de turnos? ¿Cuántos días de vacaciones tengo?'), $this->toolCall(), null);
        $this->assertTrue($verdict->isAllow(), 'the vacation half still needs convenio_search; the post-call figure guard protects the pay half');
    }

    public function test_the_rule_is_registered_on_both_prose_tools_before_the_national_law_rewrite(): void
    {
        $engine = $this->app->make(RuleEngine::class);

        foreach (['convenio_search', 'national_law'] as $tool) {
            $verdict = $engine->run("pre_call:{$tool}", $this->state('Llevo cinco años, ¿cuántos trienios cobro?'), $this->toolCall($tool));
            $this->assertSame(Verdict::DENY, $verdict->status, "{$tool}");
            $this->assertSame('salary_intent_pre_call', $verdict->rule, "{$tool}: the deny must win over national_law's convenio_search rewrite");
        }
    }

    // ---- (b) post-call -------------------------------------------------

    private function prose(string $answer, string $outcome = 'answer'): ToolResult
    {
        return new ToolResult(ToolResult::TERMINAL, terminalOutcome: new TurnOutcome($outcome, $answer, [], [], null), plannerSummary: ['status' => 'answered']);
    }

    /** @return array<string,array{0:string}> */
    public static function answersWithAnEuroAmount(): array
    {
        return [
            'wt-03 shape' => ['Para la categoría de Limpiador/a en 2026 el trienio es de 42,14 euros y el quinquenio de 43,82 euros [Fuente 2].'],
            'euro symbol after' => ['El plus se abona a razón de 45,50 € al mes [Fuente 1].'],
            'euro symbol before' => ['Se fija una cuantía de €120 [Fuente 1].'],
            'EUR' => ['El importe asciende a 300 EUR anuales [Fuente 1].'],
            'thousands separator' => ['La cuantía es de 1.250,75 euros [Fuente 1].'],
            'spelled out' => ['El complemento es de cuarenta euros mensuales [Fuente 1].'],
        ];
    }

    #[DataProvider('answersWithAnEuroAmount')]
    public function test_a_prose_answer_with_a_euro_amount_is_escalated_figure_not_from_table(string $answer): void
    {
        $rule = $this->app->make(FigureNotFromTablePostCallRule::class);
        $state = $this->state('¿Cuánto es el plus?');

        $verdict = $rule->evaluate($state, $this->toolCall(), $this->prose($answer));

        $this->assertSame(Verdict::FORCE, $verdict->status);
        $this->assertSame('escalate', $verdict->forceType);
        $this->assertSame('figure_not_from_table_guard', $verdict->rule);
        $outcome = $verdict->forcePayload;
        $this->assertSame('escalate', $outcome->outcome);
        $this->assertSame('low_confidence', $outcome->escalationReason);
        $this->assertSame('figure_not_from_table', $outcome->trace['agent']['figure_not_from_table']['sub_outcome']);
        $this->assertNotEmpty($outcome->trace['agent']['figure_not_from_table']['amounts']);
        $this->assertStringNotContainsString('euros', $outcome->answer);
    }

    public function test_prose_without_a_euro_amount_and_non_answers_pass_through(): void
    {
        $rule = $this->app->make(FigureNotFromTablePostCallRule::class);
        $state = $this->state('¿Cuántos días de vacaciones tengo?');

        $clean = 'Las vacaciones anuales son de 30 días naturales [Fuente 1]. El complemento se rige por la tabla salarial.';
        $this->assertTrue($rule->evaluate($state, $this->toolCall(), $this->prose($clean))->isAllow());

        // An escalation (e.g. an R16 miss) is not an "answer": nothing to guard.
        $this->assertTrue($rule->evaluate($state, $this->toolCall(), $this->prose('No hay material 45 euros', 'escalate'))->isAllow());

        // NO_MATERIAL result: nothing to guard.
        $this->assertTrue($rule->evaluate($state, $this->toolCall(), new ToolResult(ToolResult::NO_MATERIAL))->isAllow());
    }

    public function test_an_amount_that_salary_lookup_produced_this_turn_is_allowed(): void
    {
        $rule = $this->app->make(FigureNotFromTablePostCallRule::class);
        $state = $this->state('¿Cuánto es el plus?');
        $state->material['salary_lookup'] = $this->prose('Tu plus es de 45,50 € al mes.');

        $this->assertTrue($rule->evaluate($state, $this->toolCall(), $this->prose('El plus es de 45,50 € al mes [Fuente 1].'))->isAllow());
        $this->assertSame(Verdict::FORCE, $rule->evaluate($state, $this->toolCall(), $this->prose('El plus es de 99,99 € al mes [Fuente 1].'))->status);
    }

    public function test_the_guard_runs_before_the_prose_finish_rule_on_both_prose_tools(): void
    {
        $engine = $this->app->make(RuleEngine::class);

        foreach (['convenio_search', 'national_law'] as $tool) {
            $verdict = $engine->run("post_call:{$tool}", $this->state('¿Cuánto es el plus?'), $this->toolCall($tool), $this->prose('El plus es de 45,50 € [Fuente 1].'));
            $this->assertSame('figure_not_from_table_guard', $verdict->rule, $tool);
            $this->assertSame('escalate', $verdict->forceType, $tool);
        }
    }
}
