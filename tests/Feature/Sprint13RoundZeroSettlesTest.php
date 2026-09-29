<?php

namespace Tests\Feature;

use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\Convenio;
use App\Models\ConvenioJobCategory;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Employee;
use App\Models\ReferenceFact;
use App\Models\SalaryTable;
use App\Models\SalaryTableRow;
use App\Models\Sector;
use App\Models\Territory;
use App\Models\Topic;
use App\Services\Agent\AgentChatService;
use App\Services\Agent\PlannerClient;
use App\Services\ChatService;
use Database\Seeders\DocumentTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Sprint 13, CP-2 trace review (item 0b) — a turn that Round 0 settles makes NO planner call.
 *
 * Found on staging: a category-pick follow-up (test-andalucia-nocat, session 69, messages 5835/5837) ran Round 0
 * (`salary_lookup`), discarded the result because the session already had a turn, then paid a planner round
 * (~1.8 s, ~$0.03) that proposed the same `salary_lookup` and reached the identical outcome. The salary route is decided
 * by the question text alone and classic answers it the same way on every turn, so a salary Round-0 outcome now settles
 * the turn on a follow-up too. This file pins, with a planner that counts (and fails) every call:
 *
 *  - every Round-0-settled turn (salary first turn / follow-up / category pick, reference-fact first turn) makes 0 planner
 *    calls, records no `planner_round` step, and equals classic;
 *  - what still reaches the planner (a compound question; a reference-fact follow-up, where meaning may depend on the
 *    earlier turns) — so the change cannot silently widen.
 */
class Sprint13RoundZeroSettlesTest extends TestCase
{
    use RefreshDatabase;

    private Territory $territory;

    private Sector $sector;

    /** @var object{calls:int} */
    private object $planner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DocumentTypeSeeder::class);
        $this->territory = Territory::create(['code' => '31', 'name' => 'Navarra', 'level' => 'provincial', 'aliases' => []]);
        $this->sector = Sector::create(['name' => 'Hostelería', 'aliases' => []]);

        // A planner that counts every call and answers with the salary tool, so a call that DOES happen is visible
        // (rather than an exception being swallowed as `PlannerUnavailable` → classic fallback).
        $this->planner = new class implements PlannerClient
        {
            public int $calls = 0;

            public function plan(string $question, array $scopeSummary, array $window, array $toolDefinitions, array $priorSteps): array
            {
                $this->calls++;
                $call = $this->calls === 1
                    ? ['id' => 't1', 'tool' => 'salary_lookup', 'input' => []]
                    : ['id' => 't2', 'tool' => 'finalize', 'input' => ['use' => ['t1']]];

                return ['calls' => [$call], 'stop_reason' => 'tool_use', 'model' => null, 'request_id' => null, 'prompt_version' => null, 'tokens' => [], 'ms' => 0];
            }
        };
        $this->app->instance(PlannerClient::class, $this->planner);
    }

    private function convenio(string $numero): Convenio
    {
        return Convenio::create([
            'numero' => '13R0-'.$numero, 'name' => 'Convenio '.$numero,
            'territory_id' => $this->territory->id, 'sector_id' => $this->sector->id,
        ]);
    }

    private function employee(Convenio $convenio, ?int $jobCategoryId = null): Employee
    {
        return Employee::create([
            'email' => 'r0-'.$convenio->numero.'@example.com', 'full_name' => 'Round Zero Test',
            'convenio_id' => $convenio->id, 'job_category_id' => $jobCategoryId,
            'territory_id' => $this->territory->id, 'employment_type' => 'full_time', 'status' => 'active',
        ]);
    }

    /** @return array{0:Convenio,1:ConvenioJobCategory} a convenio with a current salary table and one category */
    private function salaryConvenio(string $numero): array
    {
        $convenio = $this->convenio($numero);
        $category = ConvenioJobCategory::create(['convenio_id' => $convenio->id, 'name' => 'Peón', 'group_code' => '1']);
        $table = SalaryTable::create(['convenio_id' => $convenio->id, 'year' => (int) now()->year]);
        SalaryTableRow::create(['salary_table_id' => $table->id, 'job_category_id' => $category->id, 'gross_annual' => 21000, 'base_salary_monthly' => 1500, 'pagas_count' => 14]);

        return [$convenio, $category];
    }

    /** A session that already has one exchange — round 0's old "follow-up" gate condition. */
    private function followUpSession(Employee $employee): ChatSession
    {
        $session = ChatSession::create(['employee_id' => $employee->id, 'started_at' => now(), 'last_activity_at' => now()]);
        ChatMessage::create(['session_id' => $session->id, 'role' => 'user', 'content' => '¿Cuánto cobro al mes?']);
        ChatMessage::create(['session_id' => $session->id, 'role' => 'assistant', 'content' => 'Necesito tu categoría.']);

        return $session;
    }

    /** @return list<array<string,mixed>> */
    private function steps(array $response): array
    {
        return $response['trace']['agent']['steps'];
    }

    private function assertSettledByRoundZero(array $response, string $route): void
    {
        $this->assertSame(0, $this->planner->calls, 'a Round-0-settled turn must make NO planner call');
        $types = array_column($this->steps($response), 'type');
        $this->assertSame(['round0'], $types, 'the only step is round0 — no planner_round, no tool_call, no rule_verdict');
        $this->assertSame([$route], $this->steps($response)[0]['seeded']);
        $this->assertSame('finalize', $response['trace']['agent']['termination']);
        $this->assertNull($response['trace']['agent']['planner'], 'no planner block: the planner was never consulted');
        $this->assertSame([], $response['trace']['agent']['window']['message_ids'], 'no window was built for this turn');
    }

    public function test_salary_first_turn_answer_makes_no_planner_call(): void
    {
        [$convenio, $category] = $this->salaryConvenio('first');
        $employee = $this->employee($convenio, $category->id);

        $response = app(AgentChatService::class)->handle($employee, '¿Cuánto cobro al mes?');

        $this->assertSame('answer', $response['outcome']);
        $this->assertSettledByRoundZero($response, 'salary_lookup');
    }

    public function test_salary_first_turn_needs_category_makes_no_planner_call(): void
    {
        [$convenio] = $this->salaryConvenio('firstnc');
        $employee = $this->employee($convenio, null);

        $response = app(AgentChatService::class)->handle($employee, '¿Cuánto cobro al mes?');

        $this->assertSame('needs_category', $response['outcome']);
        $this->assertSettledByRoundZero($response, 'salary_lookup');
    }

    public function test_salary_follow_up_needs_category_makes_no_planner_call_and_equals_classic(): void
    {
        // The staging trace: session 69, message 5835 — the same pay question asked again after a needs_category ask.
        [$convenio] = $this->salaryConvenio('fu');
        $employee = $this->employee($convenio, null);
        $session = $this->followUpSession($employee);

        $agent = app(AgentChatService::class)->handle($employee, '¿Cuánto cobro según mi convenio?', $session->uuid);
        $classic = app(ChatService::class)->handleMessage($employee, '¿Cuánto cobro según mi convenio?');

        $this->assertSame('needs_category', $agent['outcome']);
        $this->assertSettledByRoundZero($agent, 'salary_lookup');
        foreach (['outcome', 'escalated', 'escalation_reason', 'answer', 'categories'] as $field) {
            $this->assertSame($classic[$field], $agent[$field], "follow-up settled by round 0 must match classic on '{$field}'");
        }
    }

    public function test_salary_follow_up_category_pick_makes_no_planner_call_and_equals_classic(): void
    {
        // The staging trace: session 69, message 5837 — the category picked, the question repeated, answered from the table.
        [$convenio, $category] = $this->salaryConvenio('pick');
        $employee = $this->employee($convenio, null);
        $session = $this->followUpSession($employee);

        $agent = app(AgentChatService::class)->handle($employee, '¿Cuánto cobro según mi convenio?', $session->uuid, $category->id);
        $classic = app(ChatService::class)->handleMessage($employee, '¿Cuánto cobro según mi convenio?', null, $category->id);

        $this->assertSame('answer', $agent['outcome']);
        $this->assertSettledByRoundZero($agent, 'salary_lookup');
        foreach (['outcome', 'escalated', 'escalation_reason', 'answer', 'citations'] as $field) {
            $this->assertSame($classic[$field], $agent[$field], "category pick settled by round 0 must match classic on '{$field}'");
        }
        $this->assertSame($classic['trace']['salary'] ?? null, $agent['trace']['salary'] ?? null);
    }

    public function test_reference_fact_first_turn_makes_no_planner_call(): void
    {
        $convenio = $this->convenio('rf');
        $this->verifiedPeriodoDePruebaFact($convenio);
        $employee = $this->employee($convenio);

        $response = app(AgentChatService::class)->handle($employee, '¿cuál es mi periodo de prueba?');

        $this->assertSame('answer', $response['outcome']);
        $this->assertSettledByRoundZero($response, 'reference_fact');
    }

    // ---- What still reaches the planner (the change must not widen silently) ----

    public function test_a_compound_pay_and_prose_question_still_reaches_the_planner(): void
    {
        [$convenio] = $this->salaryConvenio('compound');
        $employee = $this->employee($convenio, null);

        $response = app(AgentChatService::class)->handle($employee, '¿Cuánto gano y cuántas vacaciones tengo?');

        $this->assertGreaterThanOrEqual(1, $this->planner->calls, 'a compound question is the planner\'s to route');
        $this->assertContains('planner_round', array_column($this->steps($response), 'type'));
    }

    public function test_a_reference_fact_follow_up_still_defers_to_the_planner(): void
    {
        // The follow-up gate stays for the reference-fact route: "¿y en ese caso?"-style meaning can depend on earlier turns.
        $convenio = $this->convenio('rffu');
        $this->verifiedPeriodoDePruebaFact($convenio);
        $employee = $this->employee($convenio);
        $session = $this->followUpSession($employee);

        $response = app(AgentChatService::class)->handle($employee, '¿cuál es mi periodo de prueba?', $session->uuid);

        $this->assertGreaterThanOrEqual(1, $this->planner->calls);
        $this->assertContains('planner_round', array_column($this->steps($response), 'type'));
    }

    private function verifiedPeriodoDePruebaFact(Convenio $convenio): void
    {
        $topic = Topic::firstOrCreate(['name' => 'periodo de prueba'], ['status' => 'approved']);
        $doc = Document::create([
            'uuid' => (string) Str::uuid(), 'title' => 'Periodos de prueba (referencia)', 'storage_path' => 'fake/'.Str::uuid(),
            'convenio_id' => $convenio->id, 'document_type_id' => DocumentType::where('code', 'convenio_text')->value('id'),
            'authority_level' => 'official_convenio', 'retrieval_status' => 'active', 'language' => 'es', 'tagging_status' => 'verified',
        ]);
        ReferenceFact::create([
            'convenio_id' => $convenio->id, 'topic_id' => $topic->id, 'job_category_id' => null, 'group_label' => null,
            'value' => 'periodo de prueba 90/75/60 días según contrato', 'authority_level' => ReferenceFact::AUTHORITY_LEVEL,
            'source' => 'admin_manual', 'status' => 'verified', 'source_document_id' => $doc->id, 'source_locator' => 'p.1 §1',
        ]);
    }
}
