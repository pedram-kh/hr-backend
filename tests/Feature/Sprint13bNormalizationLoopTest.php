<?php

namespace Tests\Feature;

use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\Convenio;
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
use Database\Seeders\DocumentTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Sprint 13b (plan.md §3.2, §4.1, §5) — the agent loop with a SCRIPTED planner and the real tools/rules/DB:
 *
 *  - `normalize_question` is offered on round 1 only, on the SAME /plan call (no extra planner call);
 *  - a rejected / absent / declined normalization is EXACTLY the literal path (differential vs a transcript that
 *    never proposed one) and costs no round;
 *  - an accepted one runs Round 1a (the shell runs the fact route) and answers from the verified fact, equal to the
 *    canonical twin's classic answer; a compound / follow-up turn keeps the topic for the planner's own call;
 *  - the planner cannot supply a topic id through the tool's input;
 *  - Round 0 is untouched: an anchored question settles with no planner call and no normalization step.
 */
class Sprint13bNormalizationLoopTest extends TestCase
{
    use RefreshDatabase;

    /** Unanchored (no lexicon topic anywhere): only the planner can route it. */
    private const COLLOQUIAL = 'Empecé la semana pasada, ¿cuánto dura la etapa de prueba?';

    private const CANONICAL_TWIN = '¿Cuánto dura mi periodo de prueba?';

    private Territory $territory;

    private Sector $sector;

    private Convenio $convenio;

    private Topic $topic;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DocumentTypeSeeder::class);
        $this->territory = Territory::create(['code' => '31', 'name' => 'Navarra', 'level' => 'provincial', 'aliases' => []]);
        $this->sector = Sector::create(['name' => 'Hostelería', 'aliases' => []]);
        $this->convenio = Convenio::create([
            'numero' => '13B-1', 'name' => 'Convenio Trece B',
            'territory_id' => $this->territory->id, 'sector_id' => $this->sector->id,
        ]);
        $this->topic = Topic::firstOrCreate(['name' => 'periodo de prueba'], ['status' => 'approved']);
        $this->verifiedFact('periodo de prueba de 90 días según contrato');
    }

    private function employee(): Employee
    {
        return Employee::create([
            'email' => 'n13b-'.uniqid().'@example.com', 'full_name' => 'Normalization Test',
            'convenio_id' => $this->convenio->id, 'territory_id' => $this->territory->id,
            'employment_type' => 'full_time', 'status' => 'active',
        ]);
    }

    private function verifiedFact(string $value): ReferenceFact
    {
        $doc = Document::create([
            'uuid' => (string) Str::uuid(), 'title' => 'Periodos de prueba (referencia)', 'storage_path' => 'fake/'.Str::uuid(),
            'convenio_id' => $this->convenio->id, 'document_type_id' => DocumentType::where('code', 'convenio_text')->value('id'),
            'authority_level' => 'official_convenio', 'retrieval_status' => 'active', 'language' => 'es', 'tagging_status' => 'verified',
        ]);

        return ReferenceFact::create([
            'convenio_id' => $this->convenio->id, 'topic_id' => $this->topic->id, 'job_category_id' => null, 'group_label' => null,
            'value' => $value, 'authority_level' => ReferenceFact::AUTHORITY_LEVEL, 'source' => 'admin_manual',
            'status' => 'verified', 'source_document_id' => $doc->id, 'source_locator' => 'p.1 §1',
        ]);
    }

    private function followUpSession(Employee $employee): ChatSession
    {
        $session = ChatSession::create(['employee_id' => $employee->id, 'started_at' => now(), 'last_activity_at' => now()]);
        ChatMessage::create(['session_id' => $session->id, 'role' => 'user', 'content' => 'Hola, tengo una duda.']);
        ChatMessage::create(['session_id' => $session->id, 'role' => 'assistant', 'content' => 'Claro, dime.']);

        return $session;
    }

    /**
     * A planner that plays back `$rounds` (a list of call lists) and records what it was offered.
     *
     * @param  list<list<array<string,mixed>>>  $rounds
     */
    private function planner(array $rounds): object
    {
        $planner = new class($rounds) implements PlannerClient
        {
            /** @var list<list<string>> tool names offered, per call */
            public array $offered = [];

            /** @var list<array<string,mixed>> */
            public array $scopes = [];

            public int $calls = 0;

            public function __construct(private array $rounds) {}

            public function plan(string $question, array $scopeSummary, array $window, array $toolDefinitions, array $priorSteps): array
            {
                $this->offered[] = array_column($toolDefinitions, 'name');
                $this->scopes[] = $scopeSummary;
                $round = $this->rounds[$this->calls] ?? [['id' => 'tx', 'tool' => 'finalize', 'input' => ['use' => []]]];
                $this->calls++;

                return ['calls' => $round, 'stop_reason' => 'tool_use', 'model' => 'm', 'request_id' => 'r', 'prompt_version' => 'sha256:test', 'tokens' => [], 'ms' => 0];
            }
        };
        $this->app->instance(PlannerClient::class, $planner);

        return $planner;
    }

    /** @return array{id:string,tool:string,input:array<string,mixed>} */
    private function norm(?int $topicId, ?string $canonical, float $confidence = 0.9, string $reason = 'coloquial'): array
    {
        return ['id' => 'n1', 'tool' => 'normalize_question', 'input' => ['topic_id' => $topicId, 'canonical_query' => $canonical, 'confidence' => $confidence, 'reason' => $reason]];
    }

    /** @return array{id:string,tool:string,input:array<string,mixed>} */
    private function refFact(array $input = []): array
    {
        return ['id' => 't1', 'tool' => 'reference_fact', 'input' => $input];
    }

    private function fin(): array
    {
        return ['id' => 'f1', 'tool' => 'finalize', 'input' => ['use' => ['t1']]];
    }

    /** @return list<string> */
    private function types(array $response): array
    {
        return array_column($response['trace']['agent']['steps'], 'type');
    }

    private function step(array $response, string $type): ?array
    {
        return collect($response['trace']['agent']['steps'])->firstWhere('type', $type);
    }

    // ---- offered on round 1 only, on the same call --------------------------------------------------------

    public function test_normalize_question_is_offered_on_round_one_only_and_costs_no_extra_planner_call(): void
    {
        $planner = $this->planner([[$this->refFact()], [$this->fin()]]);

        $response = app(AgentChatService::class)->handle($this->employee(), self::COLLOQUIAL);

        $this->assertSame(2, $planner->calls, 'one planner call per round — normalization adds NO call of its own');
        $this->assertContains('normalize_question', $planner->offered[0]);
        $this->assertNotContains('normalize_question', $planner->offered[1], 'offered on the first round only');
        $this->assertSame(['id' => $this->topic->id, 'name' => 'periodo de prueba', 'has_verified_fact' => true], collect($planner->scopes[0]['approved_topics'])->firstWhere('id', $this->topic->id));
        $this->assertSame('absent', $response['trace']['agent']['normalization']['verdict'], 'offered, not used → recorded as absent');
        $this->assertSame(['planner_round', 'normalization', 'tool_call', 'planner_round', 'finalize'], $this->types($response));
    }

    public function test_the_feature_flag_off_is_the_pre_13b_agent(): void
    {
        config(['hr.normalization.enabled' => false]);
        $planner = $this->planner([[$this->refFact()], [$this->fin()]]);

        $response = app(AgentChatService::class)->handle($this->employee(), self::COLLOQUIAL);

        $this->assertNotContains('normalize_question', $planner->offered[0]);
        $this->assertArrayNotHasKey('approved_topics', $planner->scopes[0]);
        $this->assertArrayNotHasKey('normalization', $response['trace']['agent']);
        $this->assertNotContains('normalization', $this->types($response));
    }

    // ---- rejected / absent / declined == the literal path ---------------------------------------------------

    public function test_a_rejected_normalization_is_exactly_the_literal_path_and_costs_no_round(): void
    {
        $over = $this->norm($this->topic->id, 'duración del periodo de prueba: 90 días naturales');

        $this->planner([[$over, $this->refFact()], [$this->fin()]]);
        $rejected = app(AgentChatService::class)->handle($this->employee(), self::COLLOQUIAL);
        $this->planner([[$this->refFact()], [$this->fin()]]);
        $literal = app(AgentChatService::class)->handle($this->employee(), self::COLLOQUIAL);

        $n = $rejected['trace']['agent']['normalization'];
        $this->assertSame('rejected', $n['verdict']);
        $this->assertContains('figure_not_in_literal', array_column($n['rejections'], 'rule'));
        $this->assertNull($n['used']);
        $this->assertNull($n['round1a'], 'a rejected normalization never runs Round 1a');
        $this->assertNotContains('round1a', $this->types($rejected));
        $this->assertNotContains('tool_denied', $this->types($rejected), 'the planner is not told; nothing is denied to it');
        $rv = collect($rejected['trace']['agent']['steps'])->firstWhere('type', 'rule_verdict');
        $this->assertSame(['rule' => 'normalization_validation', 'verdict' => 'deny'], ['rule' => $rv['rule'], 'verdict' => $rv['verdict']]);
        $this->assertSame(2, count(array_filter($this->types($rejected), fn ($t) => $t === 'planner_round')), 'no round spent on the rejection');

        // The differential: a rejected proposal changes NOTHING about the turn.
        $strip = fn (array $r) => [
            'outcome' => $r['outcome'], 'escalation_reason' => $r['escalation_reason'], 'answer' => $r['answer'], 'citations' => $r['citations'],
            'floor_decision' => $r['trace']['floor_decision'] ?? null,
            'tool_steps' => collect($r['trace']['agent']['steps'])->where('type', 'tool_call')->map(fn ($s) => [$s['tool'], $s['status'], $s['planner_summary']])->values()->all(),
        ];
        $this->assertSame($strip($literal), $strip($rejected));
        $this->assertSame('escalate', $rejected['outcome'], 'the colloquial question has no lexicon anchor, so the literal path finds no fact');
        $this->assertSame('no_material', $strip($rejected)['tool_steps'][0][1]);
    }

    public function test_declined_and_absent_normalizations_are_the_literal_path(): void
    {
        $this->planner([[$this->norm(null, null, 0.2, 'fuera de vocabulario'), $this->refFact()], [$this->fin()]]);
        $declined = app(AgentChatService::class)->handle($this->employee(), self::COLLOQUIAL);
        $this->assertSame('declined', $declined['trace']['agent']['normalization']['verdict']);
        $this->assertNotContains('round1a', $this->types($declined));
        $this->assertSame('escalate', $declined['outcome']);

        $this->planner([[$this->norm(null, null), $this->refFact()], [$this->fin()]]);
        $alsoDeclined = app(AgentChatService::class)->handle($this->employee(), self::COLLOQUIAL);
        $this->assertSame($declined['outcome'], $alsoDeclined['outcome']);
    }

    public function test_a_round_whose_only_call_is_a_normalization_is_not_malformed(): void
    {
        $planner = $this->planner([[$this->norm(null, null)], [$this->refFact()], [$this->fin()]]);

        $response = app(AgentChatService::class)->handle($this->employee(), self::COLLOQUIAL);

        $this->assertSame(3, $planner->calls);
        $this->assertSame('escalate', $response['outcome']);
        $this->assertNotSame('budget_malformed', $response['trace']['agent']['termination']);
    }

    // ---- accepted → Round 1a ----------------------------------------------------------------------------------

    public function test_an_accepted_normalization_runs_round_1a_and_answers_from_the_verified_fact_like_its_canonical_twin(): void
    {
        $planner = $this->planner([[$this->norm($this->topic->id, 'duración del período de prueba', 0.9), $this->refFact()], [$this->fin()]]);
        $employee = $this->employee();

        $agent = app(AgentChatService::class)->handle($employee, self::COLLOQUIAL);
        $classicTwin = app(ChatService::class)->handleMessage($employee, self::CANONICAL_TWIN);

        $this->assertSame(1, $planner->calls, 'Round 1a settled the turn inside the first round');
        $this->assertSame('answer', $agent['outcome']);
        $this->assertStringContainsString('90 días', $agent['answer']);
        $this->assertSame($classicTwin['answer'], $agent['answer'], 'the colloquial question gets the canonical twin\'s answer');
        $this->assertSame($classicTwin['citations'], $agent['citations']);

        $this->assertSame(['planner_round', 'normalization', 'round1a', 'rule_verdict'], $this->types($agent), 'the terminal post-call verdict ends the turn (as for Round 0\'s route)');
        $this->assertSame('forced_finish', $agent['trace']['agent']['termination']);
        $this->assertSame('reference_fact', $agent['trace']['floor_decision']['path']);
        $this->assertSame('planner_normalized_reference_fact', $agent['trace']['router_decision']['source']);

        $n = $agent['trace']['agent']['normalization'];
        $this->assertSame('accepted', $n['verdict']);
        $this->assertSame(self::COLLOQUIAL, $n['literal']);
        $this->assertSame(['topic_id' => $this->topic->id, 'topic_name' => 'periodo de prueba', 'canonical_query' => 'duración del período de prueba'], $n['used']);
        $this->assertSame(['ran' => true, 'active' => false, 'topic_id' => $this->topic->id], $n['round1a']);
        $this->assertCount(1, $n['consumers']);
        $this->assertSame('reference_fact', $n['consumers'][0]['tool']);
        $this->assertSame('round_1a', $n['consumers'][0]['via']);
        $this->assertNull($n['consumers'][0]['lexicon_topic_id'], 'the lexicon could not see this question — that is the whole point');
        $this->assertTrue($n['consumers'][0]['rescued_answer'], 'an answer the literal path could not give: counted as a rescue');
        $this->assertSame('sha256:test', $n['planner_prompt_version']);
    }

    public function test_round_1a_precedes_and_replaces_the_planners_own_calls_and_an_escalate_in_the_same_round_does_not_pre_empt_it(): void
    {
        $escalate = ['id' => 'e1', 'tool' => 'escalate', 'input' => ['category' => 'other', 'reason' => 'dudo']];
        $planner = $this->planner([[$escalate, $this->norm($this->topic->id, 'duración del período de prueba'), $this->refFact()], [$this->fin()]]);

        $response = app(AgentChatService::class)->handle($this->employee(), self::COLLOQUIAL);

        $this->assertSame('answer', $response['outcome'], 'a rule-mandated fact answer wins over a same-round planner escalate (plan §4.1)');
        $this->assertNotContains('planner_escalate', $this->types($response), 'the turn ended on the Round 1a fact; the planner\'s escalate never ran');
        $this->assertSame(1, $planner->calls);
    }

    public function test_a_follow_up_skips_round_1a_but_the_planners_own_reference_fact_call_still_gets_the_topic(): void
    {
        $employee = $this->employee();
        $session = $this->followUpSession($employee);
        $this->planner([[$this->norm($this->topic->id, 'duración del período de prueba'), $this->refFact()], [$this->fin()]]);

        $response = app(AgentChatService::class)->handle($employee, self::COLLOQUIAL, $session->uuid);

        $n = $response['trace']['agent']['normalization'];
        $this->assertSame(['ran' => false, 'skipped' => 'follow_up'], $n['round1a']);
        $this->assertNotContains('round1a', $this->types($response));
        $this->assertSame('answer', $response['outcome'], 'the planner-run route still uses the validated topic');
        $this->assertSame('planner_call', $n['consumers'][0]['via']);
    }

    public function test_low_confidence_drops_the_topic_so_no_fact_route_is_taken(): void
    {
        $this->planner([[$this->norm($this->topic->id, 'duración del período de prueba', 0.4), $this->refFact()], [$this->fin()]]);

        $response = app(AgentChatService::class)->handle($this->employee(), self::COLLOQUIAL);

        $n = $response['trace']['agent']['normalization'];
        $this->assertSame('accepted', $n['verdict']);
        $this->assertTrue($n['topic_dropped']);
        $this->assertNull($n['used']['topic_id']);
        $this->assertNotContains('round1a', $this->types($response));
        $this->assertSame('escalate', $response['outcome']);
    }

    public function test_an_unapproved_topic_is_rejected_and_never_routes(): void
    {
        $draft = Topic::create(['name' => 'borrador', 'status' => 'proposed']);
        $this->planner([[$this->norm($draft->id, 'duración del período de prueba'), $this->refFact()], [$this->fin()]]);

        $response = app(AgentChatService::class)->handle($this->employee(), self::COLLOQUIAL);

        $n = $response['trace']['agent']['normalization'];
        $this->assertSame('rejected', $n['verdict']);
        $this->assertContains('topic_not_approved', array_column($n['rejections'], 'rule'));
        $this->assertSame('escalate', $response['outcome']);
    }

    public function test_the_planner_cannot_smuggle_a_topic_through_the_tools_own_input(): void
    {
        $this->planner([[$this->refFact(['topic_id' => $this->topic->id])], [$this->fin()]]);

        $response = app(AgentChatService::class)->handle($this->employee(), self::COLLOQUIAL);

        $this->assertSame('escalate', $response['outcome'], 'the topic id arrives only via a validated normalization, never via reference_fact input');
        $this->assertSame('no_material', collect($response['trace']['agent']['steps'])->firstWhere('tool', 'reference_fact')['status']);
    }

    public function test_a_normalization_offered_nowhere_else_is_dropped_on_later_rounds(): void
    {
        $this->planner([[$this->refFact()], [$this->norm($this->topic->id, 'duración del período de prueba'), $this->fin()]]);

        $response = app(AgentChatService::class)->handle($this->employee(), self::COLLOQUIAL);

        $this->assertSame('absent', $response['trace']['agent']['normalization']['verdict'], 'a stray round-2 call is ignored, not honoured');
        $this->assertNotContains('round1a', $this->types($response));
    }

    // ---- Round 0 untouched -------------------------------------------------------------------------------------

    public function test_round_zero_still_settles_anchored_questions_with_no_planner_call_and_no_normalization(): void
    {
        $planner = $this->planner([]);

        $response = app(AgentChatService::class)->handle($this->employee(), '¿cuál es mi periodo de prueba?');

        $this->assertSame(0, $planner->calls);
        $this->assertSame(['round0'], $this->types($response));
        $this->assertArrayNotHasKey('normalization', $response['trace']['agent']);
        $this->assertSame('answer', $response['outcome']);
        $this->assertSame('deterministic_reference_fact', $response['trace']['router_decision']['source']);
    }
}
