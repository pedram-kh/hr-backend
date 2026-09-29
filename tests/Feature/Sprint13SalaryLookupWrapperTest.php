<?php

namespace Tests\Feature;

use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\Convenio;
use App\Models\ConvenioJobCategory;
use App\Models\Employee;
use App\Models\SalaryTable;
use App\Models\SalaryTableRow;
use App\Models\Sector;
use App\Models\Territory;
use App\Services\Agent\AgentChatService;
use App\Services\Agent\PlannerClient;
use App\Services\Agent\TurnState;
use App\Services\Answer\TurnPersister;
use App\Services\ChatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Sprint 13, build step 5 (plan.md §B.3.1) — proves `Tools\SalaryLookupTool`
 * + `Rules\SalaryLookupPostCallRule` reproduce classic's salary branch
 * exactly, across every one of `SalaryPath::handle()`'s outcome shapes
 * (answer, needs_category, coverage gap, cross-path, SMI) — the SAME five
 * shapes `Sprint13GoldenTraceTest` cases 07/08/09/10/11 already pin for
 * CLASSIC. Round 0 settles every single, non-compound salary question BEFORE the
 * planner (first turn or follow-up alike — CP-2, `Sprint13RoundZeroSettlesTest`), so
 * to actually exercise the wrapper code (the `Tool`, its `definition()`/`run()`, and
 * the post-call rule) these cases drive `AgentChatService`'s planner loop DIRECTLY
 * (private `loop()` via reflection), then stamp and persist exactly as `handle()`
 * does. This is the route a compound question, or a pay question the planner itself
 * declares, takes in production. `SalaryPath::handle()` is a pure function of
 * employee/question/date/category — it does not read session history — so the outcome
 * must be byte-identical to classic's.
 */
class Sprint13SalaryLookupWrapperTest extends TestCase
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
            'numero' => '13SLW-'.$numero, 'name' => 'Convenio '.$numero,
            'territory_id' => $this->territory->id, 'sector_id' => $this->sector->id,
        ]);
    }

    private function employee(Convenio $convenio, ?int $jobCategoryId = null): Employee
    {
        return Employee::create([
            'email' => 'slw-'.$convenio->numero.'@example.com', 'full_name' => 'Salary Wrapper Test',
            'convenio_id' => $convenio->id, 'job_category_id' => $jobCategoryId,
            'territory_id' => $this->territory->id, 'employment_type' => 'full_time', 'status' => 'active',
        ]);
    }

    /** A follow-up session for `$employee` — one prior exchange already exists. */
    private function followUpSession(Employee $employee): ChatSession
    {
        $session = ChatSession::create(['employee_id' => $employee->id, 'started_at' => now(), 'last_activity_at' => now()]);
        ChatMessage::create(['session_id' => $session->id, 'role' => 'user', 'content' => 'Hola, tengo una duda.']);
        ChatMessage::create(['session_id' => $session->id, 'role' => 'assistant', 'content' => 'Claro, dime.']);

        return $session;
    }

    /** A planner that calls `salary_lookup` immediately in round 1, then `finalize` if the tool didn't already terminate the turn. */
    private function salaryLookupPlanner(): PlannerClient
    {
        return new class implements PlannerClient
        {
            private int $round = 0;

            public function plan(string $question, array $scopeSummary, array $window, array $toolDefinitions, array $priorSteps): array
            {
                $this->round++;
                if ($this->round === 1) {
                    return ['calls' => [['id' => 't1', 'tool' => 'salary_lookup', 'input' => []]], 'stop_reason' => 'tool_use', 'model' => null, 'request_id' => null, 'prompt_version' => null, 'tokens' => [], 'ms' => 0];
                }

                return ['calls' => [['id' => 't2', 'tool' => 'finalize', 'input' => ['use' => ['t1']]]], 'stop_reason' => 'tool_use', 'model' => null, 'request_id' => null, 'prompt_version' => null, 'tokens' => [], 'ms' => 0];
            }
        };
    }

    /** @return array<string,mixed> the fields that must match classic, regardless of follow-up status. */
    private function comparableFields(array $response): array
    {
        return [
            'outcome' => $response['outcome'],
            'escalated' => $response['escalated'],
            'escalation_reason' => $response['escalation_reason'],
            'answer' => $response['answer'],
            'citations' => $response['citations'],
            'categories' => $response['categories'],
            'salary' => $response['trace']['salary'] ?? null,
            'floor_decision' => $response['trace']['floor_decision'] ?? null,
        ];
    }

    private function runViaAgentWrapper(Employee $employee, string $question, ?int $selectedJobCategoryId = null): array
    {
        $this->app->bind(PlannerClient::class, fn () => $this->salaryLookupPlanner());
        $session = $this->followUpSession($employee);
        $service = app(AgentChatService::class);

        // Same skeleton `AgentChatService::handle()` builds before round 0.
        $state = new TurnState($employee, $question, Carbon::today(), $session, [
            'profile' => ['employee_uuid' => $employee->uuid, 'convenio_id' => $employee->convenio_id],
            'scope_filters' => ['convenio_id' => $employee->convenio_id, 'include_national_law' => true, 'retrieval_status' => ['active'], 'as_of_date' => Carbon::today()->toDateString()],
            'router_decision' => null,
            'guardrail_check' => ['fired' => false, 'reason' => null, 'rule' => null],
        ], $selectedJobCategoryId);

        $loop = new ReflectionMethod($service, 'loop');
        $stamp = new ReflectionMethod($service, 'stampAgentTrace');
        $outcome = $loop->invoke($service, $state, null);
        $outcome = $stamp->invoke($service, $outcome, $state, $state->terminationReason);

        return app(TurnPersister::class)->persist($session, $employee, $question, $outcome);
    }

    private function runViaClassic(Employee $employee, string $question, ?int $selectedJobCategoryId = null): array
    {
        return app(ChatService::class)->handleMessage($employee, $question, null, $selectedJobCategoryId);
    }

    public function test_plain_answer_matches_classic(): void
    {
        $convenio = $this->convenio('answer');
        $category = ConvenioJobCategory::create(['convenio_id' => $convenio->id, 'name' => 'Peón', 'group_code' => '1']);
        $table = SalaryTable::create(['convenio_id' => $convenio->id, 'year' => (int) now()->year]);
        SalaryTableRow::create(['salary_table_id' => $table->id, 'job_category_id' => $category->id, 'gross_annual' => 21000, 'base_salary_monthly' => 1500, 'pagas_count' => 14]);
        $employee = $this->employee($convenio, jobCategoryId: $category->id);

        $classic = $this->runViaClassic($employee, '¿Cuánto gano?');
        $agent = $this->runViaAgentWrapper($employee, '¿Cuánto gano?');

        $this->assertSame('answer', $classic['outcome']);
        $this->assertSame($this->comparableFields($classic), $this->comparableFields($agent));
    }

    public function test_needs_category_matches_classic_and_counts_as_a_clarification(): void
    {
        $convenio = $this->convenio('needscat');
        $category = ConvenioJobCategory::create(['convenio_id' => $convenio->id, 'name' => 'Peón', 'group_code' => '1']);
        $table = SalaryTable::create(['convenio_id' => $convenio->id, 'year' => (int) now()->year]);
        SalaryTableRow::create(['salary_table_id' => $table->id, 'job_category_id' => $category->id, 'gross_annual' => 21000, 'base_salary_monthly' => 1500, 'pagas_count' => 14]);
        $employee = $this->employee($convenio, jobCategoryId: null);

        $classic = $this->runViaClassic($employee, '¿Cuánto gano?');
        $agent = $this->runViaAgentWrapper($employee, '¿Cuánto gano?');

        $this->assertSame('needs_category', $classic['outcome']);
        $this->assertSame($this->comparableFields($classic), $this->comparableFields($agent));
        $this->assertSame('needs_category', $agent['trace']['agent']['termination']);
    }

    public function test_coverage_gap_no_table_matches_classic(): void
    {
        $convenio = $this->convenio('notable');
        $employee = $this->employee($convenio);

        $classic = $this->runViaClassic($employee, '¿Cuánto gano?');
        $agent = $this->runViaAgentWrapper($employee, '¿Cuánto gano?');

        $this->assertSame('escalate', $classic['outcome']);
        $this->assertSame('salary_coverage_gap', $classic['escalation_reason']);
        $this->assertSame($this->comparableFields($classic), $this->comparableFields($agent));
    }

    public function test_smi_statutory_figure_matches_classic(): void
    {
        $convenio = $this->convenio('smi');
        $employee = $this->employee($convenio);

        $classic = $this->runViaClassic($employee, '¿Cuál es el SMI este año?');
        $agent = $this->runViaAgentWrapper($employee, '¿Cuál es el SMI este año?');

        $this->assertSame('escalate', $classic['outcome']);
        $this->assertSame('salary_coverage_gap', $classic['escalation_reason']);
        $this->assertSame($this->comparableFields($classic), $this->comparableFields($agent));
    }

    public function test_cross_path_matches_classic(): void
    {
        $convenio = $this->convenio('crosspath');
        $employee = $this->employee($convenio);

        $classic = $this->runViaClassic($employee, '¿Cuánto gano y cuántas vacaciones tengo?');
        $agent = $this->runViaAgentWrapper($employee, '¿Cuánto gano y cuántas vacaciones tengo?');

        $this->assertSame('escalate', $classic['outcome']);
        $this->assertSame('low_confidence', $classic['escalation_reason']);
        $this->assertSame($this->comparableFields($classic), $this->comparableFields($agent));
    }

    public function test_selected_job_category_id_flows_through_the_wrapper(): void
    {
        $convenio = $this->convenio('pick');
        $category = ConvenioJobCategory::create(['convenio_id' => $convenio->id, 'name' => 'Peón', 'group_code' => '1']);
        $table = SalaryTable::create(['convenio_id' => $convenio->id, 'year' => (int) now()->year]);
        SalaryTableRow::create(['salary_table_id' => $table->id, 'job_category_id' => $category->id, 'gross_annual' => 21000, 'base_salary_monthly' => 1500, 'pagas_count' => 14]);
        $employee = $this->employee($convenio, jobCategoryId: null);

        // The second request of classic's own two-turn needs_category flow: the
        // employee's follow-up carries `selected_job_category_id` back in
        // (`ChatController.php:37`) — proves `TurnState::$selectedJobCategoryId`
        // (this step's own additive fix) actually reaches `SalaryPath::handle()`
        // from a tool call in round 1, not just from round 0.
        $classic = $this->runViaClassic($employee, '¿Cuánto gano?', $category->id);
        $agent = $this->runViaAgentWrapper($employee, '¿Cuánto gano?', $category->id);

        $this->assertSame('answer', $classic['outcome']);
        $this->assertSame($this->comparableFields($classic), $this->comparableFields($agent));
    }
}
