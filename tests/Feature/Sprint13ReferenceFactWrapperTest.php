<?php

namespace Tests\Feature;

use App\Models\AnswerModelSetting;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\Convenio;
use App\Models\ConvenioJobCategory;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Employee;
use App\Models\ReferenceFact;
use App\Models\Sector;
use App\Models\Territory;
use App\Models\Topic;
use App\Services\Agent\AgentChatService;
use App\Services\Agent\PlannerClient;
use App\Services\ChatService;
use App\Services\ExtractionClient;
use Database\Seeders\DocumentTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Sprint 13, build step 5 (plan.md §B.3.2) — proves `Tools\ReferenceFactTool`
 * + `Rules\ReferenceFactPostCallRule`/`ReferenceFactSalaryPrecedenceRule`
 * reproduce classic's reference-fact branch exactly, across the same shapes
 * `Sprint13GoldenTraceTest` cases 12/13/14/15 pin for classic (P1 quote, P2
 * composition, same-point conflict, coverage-gap tier 4), plus the ONE
 * outcome classic's OWN golden-trace suite has no dedicated case for because
 * it is not an escalation at all: `no_fact`, where the planner must be
 * allowed to keep going (§B.3.2 — NOT terminal, unlike every other tool
 * result in this sprint's v1 set).
 *
 * Every case is asked as a FOLLOW-UP for the same reason
 * `Sprint13SalaryLookupWrapperTest` uses it: round 0 would otherwise
 * short-circuit through `ReferenceFactPath` directly before the planner/tool
 * machinery is ever reached.
 */
class Sprint13ReferenceFactWrapperTest extends TestCase
{
    use RefreshDatabase;

    private Territory $territory;

    private Sector $sector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DocumentTypeSeeder::class);
        $this->territory = Territory::create(['code' => '31', 'name' => 'Navarra', 'level' => 'provincial', 'aliases' => []]);
        $this->sector = Sector::create(['name' => 'Hostelería', 'aliases' => []]);
    }

    private function convenio(string $numero): Convenio
    {
        return Convenio::create([
            'numero' => '13RFW-'.$numero, 'name' => 'Convenio '.$numero,
            'territory_id' => $this->territory->id, 'sector_id' => $this->sector->id,
        ]);
    }

    private function employee(Convenio $convenio, ?int $jobCategoryId = null): Employee
    {
        return Employee::create([
            'email' => 'rfw-'.$convenio->numero.'@example.com', 'full_name' => 'Reference Fact Wrapper Test',
            'convenio_id' => $convenio->id, 'job_category_id' => $jobCategoryId,
            'territory_id' => $this->territory->id, 'employment_type' => 'full_time', 'status' => 'active',
        ]);
    }

    private function doc(Convenio $convenio, string $title, string $authority): Document
    {
        return Document::create([
            'uuid' => (string) Str::uuid(),
            'title' => $title,
            'storage_path' => 'fake/'.Str::uuid(),
            'convenio_id' => $convenio->id,
            'document_type_id' => DocumentType::where('code', 'convenio_text')->value('id'),
            'authority_level' => $authority,
            'retrieval_status' => 'active',
            'language' => 'es',
            'tagging_status' => 'verified',
        ]);
    }

    private function configureAnswerModel(): void
    {
        AnswerModelSetting::query()->delete();
        $s = new AnswerModelSetting(['provider' => 'claude']);
        $s->id = 1;
        $s->setKey('test-key-1234');
    }

    /** @param  array<string, array<string,mixed>|callable>  $script */
    private function bindAi(array $script): void
    {
        $fake = new class($script) extends ExtractionClient
        {
            public function __construct(private array $script) {}

            private function resolve(string $key, array $args): array
            {
                if (! array_key_exists($key, $this->script)) {
                    throw new \RuntimeException("reference-fact wrapper fixture: unexpected /{$key} call");
                }
                $v = $this->script[$key];

                return is_callable($v) ? $v(...$args) : $v;
            }

            public function route(string $question, string $decryptedKey, array $providerConfig): array
            {
                return $this->resolve('route', [$question]);
            }

            public function retrieve(array $params): array
            {
                return $this->resolve('retrieve', [$params]);
            }

            public function synthesise(string $question, array $chunks, string $decryptedKey, array $providerConfig): array
            {
                return $this->resolve('synthesise', [$question, $chunks]);
            }

            public function ground(string $question, string $answer, array $chunks, string $decryptedKey, array $providerConfig): array
            {
                return $this->resolve('ground', [$question, $answer, $chunks]);
            }
        };

        $this->app->instance(ExtractionClient::class, $fake);
    }

    private function followUpSession(Employee $employee): ChatSession
    {
        $session = ChatSession::create(['employee_id' => $employee->id, 'started_at' => now(), 'last_activity_at' => now()]);
        ChatMessage::create(['session_id' => $session->id, 'role' => 'user', 'content' => 'Hola, tengo una duda.']);
        ChatMessage::create(['session_id' => $session->id, 'role' => 'assistant', 'content' => 'Claro, dime.']);

        return $session;
    }

    private function referenceFactPlanner(): PlannerClient
    {
        return new class implements PlannerClient
        {
            private int $round = 0;

            public function plan(string $question, array $scopeSummary, array $window, array $toolDefinitions, array $priorSteps): array
            {
                $this->round++;
                if ($this->round === 1) {
                    return ['calls' => [['id' => 't1', 'tool' => 'reference_fact', 'input' => []]], 'stop_reason' => 'tool_use', 'model' => null, 'request_id' => null, 'prompt_version' => null, 'tokens' => [], 'ms' => 0];
                }

                return ['calls' => [['id' => 't2', 'tool' => 'finalize', 'input' => ['use' => ['t1']]]], 'stop_reason' => 'tool_use', 'model' => null, 'request_id' => null, 'prompt_version' => null, 'tokens' => [], 'ms' => 0];
            }
        };
    }

    private function comparableFields(array $response): array
    {
        return [
            'outcome' => $response['outcome'],
            'escalated' => $response['escalated'],
            'escalation_reason' => $response['escalation_reason'],
            'answer' => $response['answer'],
            'citations' => $response['citations'],
            'reference_fact' => $response['trace']['reference_fact'] ?? null,
            'composition' => $response['trace']['composition'] ?? null,
            'floor_decision' => $response['trace']['floor_decision'] ?? null,
        ];
    }

    private function runViaAgentWrapper(Employee $employee, string $question): array
    {
        $this->app->bind(PlannerClient::class, fn () => $this->referenceFactPlanner());
        $session = $this->followUpSession($employee);

        return app(AgentChatService::class)->handle($employee, $question, $session->uuid);
    }

    private function runViaClassic(Employee $employee, string $question): array
    {
        return app(ChatService::class)->handleMessage($employee, $question);
    }

    public function test_p1_quote_matches_classic(): void
    {
        $convenio = $this->convenio('p1');
        $topic = Topic::firstOrCreate(['name' => 'periodo de prueba'], ['status' => 'approved']);
        $doc = $this->doc($convenio, 'Periodos de prueba (referencia)', 'official_convenio');
        ReferenceFact::create([
            'convenio_id' => $convenio->id, 'topic_id' => $topic->id,
            'job_category_id' => null, 'group_label' => null,
            'value' => 'periodo de prueba 90/75/60 días según contrato',
            'authority_level' => ReferenceFact::AUTHORITY_LEVEL,
            'source' => 'admin_manual', 'status' => 'verified',
            'source_document_id' => $doc->id, 'source_locator' => 'p.1 §1',
        ]);
        $employee = $this->employee($convenio);
        $this->bindAi([]); // no answer model configured → no hr-ai call at all

        $classic = $this->runViaClassic($employee, '¿cuál es mi periodo de prueba?');
        $agent = $this->runViaAgentWrapper($employee, '¿cuál es mi periodo de prueba?');

        $this->assertSame('answer', $classic['outcome']);
        $this->assertSame('reference_fact', $classic['trace']['floor_decision']['path']);
        $this->assertSame($this->comparableFields($classic), $this->comparableFields($agent));
    }

    public function test_p2_composition_matches_classic(): void
    {
        $convenio = $this->convenio('p2');
        $topic = Topic::firstOrCreate(['name' => 'periodo de prueba'], ['status' => 'approved']);
        $factDoc = $this->doc($convenio, 'Periodos de prueba (referencia)', 'official_convenio');
        $proseDoc = $this->doc($convenio, 'Convenio (texto)', 'official_convenio');
        ReferenceFact::create([
            'convenio_id' => $convenio->id, 'topic_id' => $topic->id,
            'job_category_id' => null, 'group_label' => null,
            'value' => 'periodo de prueba 90 días',
            'authority_level' => ReferenceFact::AUTHORITY_LEVEL,
            'source' => 'admin_manual', 'status' => 'verified',
            'source_document_id' => $factDoc->id, 'source_locator' => 'p.3 §2',
        ]);
        $employee = $this->employee($convenio);
        $this->configureAnswerModel();
        $chunkId = DB::table('document_chunks')->insertGetId([
            'document_id' => $proseDoc->id, 'chunk_index' => 0,
            'page_from' => 3, 'page_to' => 3, 'content' => 'El periodo de prueba para el personal será de 90 días naturales.',
            'token_count' => 12, 'convenio_id' => $convenio->id, 'retrieval_status' => 'active',
            'authority_level' => 'official_convenio', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->bindAi([
            'retrieve' => fn (array $p) => ($p['convenio_id'] ?? null) === null
                ? ['chunks' => [], 'eligible_total' => 0]
                : ['chunks' => [[
                    'id' => $chunkId, 'document_id' => $proseDoc->id, 'page_from' => 3, 'page_to' => 3,
                    'content' => 'El periodo de prueba para el personal será de 90 días naturales.',
                    'score' => 0.91, 'authority_level' => 'official_convenio',
                ]], 'eligible_total' => 1],
            'synthesise' => [
                'answer' => 'Tu periodo de prueba es de 90 días [Fuente 1][Fuente 2].',
                'confidence' => 0.92,
                'authority_used' => ['official_convenio', 'structured_reference'],
                'citations' => [
                    ['chunk_id' => $chunkId, 'source_type' => 'chunk', 'document_id' => $proseDoc->id, 'page_from' => 3, 'page_to' => 3, 'authority_level' => 'official_convenio'],
                    ['chunk_id' => null, 'source_type' => 'reference_fact', 'document_id' => $factDoc->id, 'authority_level' => 'structured_reference'],
                ],
                'trace_fragment' => [],
            ],
            'ground' => ['grounded' => true, 'claims' => [['claim' => 'periodo de prueba 90 días', 'grounded' => true, 'supporting_source' => 1]], 'ungrounded' => [], 'trace_fragment' => []],
        ]);

        $classic = $this->runViaClassic($employee, '¿cuál es mi periodo de prueba?');
        $agent = $this->runViaAgentWrapper($employee, '¿cuál es mi periodo de prueba?');

        $this->assertSame('answer', $classic['outcome']);
        $this->assertSame('reference_fact_composition', $classic['trace']['floor_decision']['path']);
        $this->assertSame($this->comparableFields($classic), $this->comparableFields($agent));
    }

    public function test_same_point_conflict_matches_classic(): void
    {
        $convenio = $this->convenio('conflict');
        $topic = Topic::firstOrCreate(['name' => 'periodo de prueba'], ['status' => 'approved']);
        $factDoc = $this->doc($convenio, 'Periodos de prueba (referencia)', 'official_convenio');
        $proseDoc = $this->doc($convenio, 'Convenio (texto)', 'official_convenio');
        ReferenceFact::create([
            'convenio_id' => $convenio->id, 'topic_id' => $topic->id,
            'job_category_id' => null, 'group_label' => null,
            'value' => 'periodo de prueba 90 días',
            'authority_level' => ReferenceFact::AUTHORITY_LEVEL,
            'source' => 'admin_manual', 'status' => 'verified',
            'source_document_id' => $factDoc->id, 'source_locator' => 'p.3 §2',
        ]);
        $employee = $this->employee($convenio);
        $this->configureAnswerModel();
        $chunkId = DB::table('document_chunks')->insertGetId([
            'document_id' => $proseDoc->id, 'chunk_index' => 0,
            'page_from' => 3, 'page_to' => 3, 'content' => 'El periodo de prueba será de 60 días.',
            'token_count' => 8, 'convenio_id' => $convenio->id, 'retrieval_status' => 'active',
            'authority_level' => 'official_convenio', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->bindAi([
            'retrieve' => fn (array $p) => ($p['convenio_id'] ?? null) === null
                ? ['chunks' => [], 'eligible_total' => 0]
                : ['chunks' => [[
                    'id' => $chunkId, 'document_id' => $proseDoc->id, 'page_from' => 3, 'page_to' => 3,
                    'content' => 'El periodo de prueba será de 60 días.',
                    'score' => 0.91, 'authority_level' => 'official_convenio',
                ]], 'eligible_total' => 1],
        ]);

        $classic = $this->runViaClassic($employee, '¿cuál es mi periodo de prueba?');
        $agent = $this->runViaAgentWrapper($employee, '¿cuál es mi periodo de prueba?');

        $this->assertSame('escalate', $classic['outcome']);
        $this->assertSame('conflict', $classic['escalation_reason']);
        $this->assertSame($this->comparableFields($classic), $this->comparableFields($agent));
    }

    public function test_coverage_gap_tier4_matches_classic(): void
    {
        $convenio = $this->convenio('tier4');
        $topic = Topic::firstOrCreate(['name' => 'periodo de prueba'], ['status' => 'approved']);
        $categoryA = ConvenioJobCategory::create(['convenio_id' => $convenio->id, 'name' => 'Camarero/a', 'group_code' => '1']);
        $categoryB = ConvenioJobCategory::create(['convenio_id' => $convenio->id, 'name' => 'Cocinero/a', 'group_code' => '2']);
        $doc = $this->doc($convenio, 'Periodos de prueba (referencia)', 'official_convenio');
        ReferenceFact::create([
            'convenio_id' => $convenio->id, 'topic_id' => $topic->id,
            'job_category_id' => $categoryB->id, 'group_label' => null,
            'value' => 'periodo de prueba 60 días',
            'authority_level' => ReferenceFact::AUTHORITY_LEVEL,
            'source' => 'admin_manual', 'status' => 'verified',
            'source_document_id' => $doc->id,
        ]);
        $employee = $this->employee($convenio, jobCategoryId: $categoryA->id);
        $this->bindAi([]);

        $classic = $this->runViaClassic($employee, '¿cuál es mi periodo de prueba?');
        $agent = $this->runViaAgentWrapper($employee, '¿cuál es mi periodo de prueba?');

        $this->assertSame('escalate', $classic['outcome']);
        $this->assertSame('reference_fact_coverage_gap', $classic['escalation_reason']);
        $this->assertSame($this->comparableFields($classic), $this->comparableFields($agent));
    }

    /**
     * The one shape classic's OWN golden-trace suite has no case for because
     * it isn't an escalation: `no_fact`. Proves the tool returns `NO_MATERIAL`
     * (not a forced verdict) and the planner is genuinely still running
     * afterward — asserted here by having the SAME scripted planner call
     * `reference_fact` (no_fact), then a second, DIFFERENT tool
     * (`salary_lookup`, on a salary-shaped follow-up question) in round 2,
     * proving the loop did not terminate after the no_fact result.
     */
    public function test_no_fact_is_not_terminal_and_the_planner_keeps_going(): void
    {
        $convenio = $this->convenio('nofact');
        $employee = $this->employee($convenio); // no salary table → coverage gap, but that's fine: we only care that BOTH tools ran
        $session = $this->followUpSession($employee);

        $planner = new class implements PlannerClient
        {
            private int $round = 0;

            public function plan(string $question, array $scopeSummary, array $window, array $toolDefinitions, array $priorSteps): array
            {
                $this->round++;

                return match ($this->round) {
                    1 => ['calls' => [['id' => 't1', 'tool' => 'reference_fact', 'input' => []]], 'stop_reason' => 'tool_use', 'model' => null, 'request_id' => null, 'prompt_version' => null, 'tokens' => [], 'ms' => 0],
                    default => ['calls' => [['id' => 't2', 'tool' => 'salary_lookup', 'input' => []]], 'stop_reason' => 'tool_use', 'model' => null, 'request_id' => null, 'prompt_version' => null, 'tokens' => [], 'ms' => 0],
                };
            }
        };
        $this->app->bind(PlannerClient::class, fn () => $planner);

        // A question with no lexicon topic match → detectTopic() returns null.
        $response = app(AgentChatService::class)->handle($employee, 'Cuéntame algo que no tenga ningún tema reconocido, por favor.', $session->uuid);

        $steps = $response['trace']['agent']['steps'];

        // reference_fact ran and left a NO_MATERIAL `tool_call` step — since
        // that status is NOT terminal, `runToolCall()` does not force
        // anything and the loop must go back to the planner for round 2.
        $referenceFactSteps = array_values(array_filter($steps, fn ($s) => ($s['type'] ?? null) === 'tool_call' && $s['tool'] === 'reference_fact'));
        $this->assertCount(1, $referenceFactSteps, 'reference_fact must have actually run');
        $this->assertSame('no_material', $referenceFactSteps[0]['status']);

        // A second `planner_round` step (round 2) proves the loop asked the
        // planner again instead of stopping after the first tool's result —
        // `no_fact` is the one v1 shape in this sprint that is NOT terminal.
        $plannerRounds = array_values(array_filter($steps, fn ($s) => ($s['type'] ?? null) === 'planner_round'));
        $this->assertCount(2, $plannerRounds);
        $this->assertSame('salary_lookup', $plannerRounds[1]['calls'][0]['tool']);

        // salary_lookup's own post-call rule then force-escalates the turn
        // (no salary table for this employee/convenio → coverage gap) — a
        // forced verdict short-circuits BEFORE a `tool_call` step is
        // recorded (see `runToolCall()`), so the escalation reason itself —
        // reachable only by the tool actually running — is the proof the
        // SECOND tool executed after the first one's NO_MATERIAL result.
        $this->assertTrue($response['escalated']);
        $this->assertSame('salary_coverage_gap', $response['escalation_reason']);
    }
}
