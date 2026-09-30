<?php

namespace Tests\Feature;

use App\Models\AnswerModelSetting;
use App\Models\Convenio;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Employee;
use App\Models\Sector;
use App\Models\Territory;
use App\Services\Agent\AgentChatService;
use App\Services\Agent\PlannerClient;
use App\Services\ExtractionClient;
use Database\Seeders\DocumentTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Sprint 13b (plan.md §4.2, §8.5 Q6) — the prose tools' consumption of a validated canonical:
 *  - the canonical is an EXTRA retrieval pass; the employee's LITERAL question stays the question that
 *    `/synthesise` and `/ground` see, and the national-law pass keeps the literal;
 *  - a canonical that lifts the union over the Check-A floor is a counted, listed rescue — and the
 *    counterfactual (no normalization) is the ordinary Check-A escalation;
 *  - `convenio_search`'s planner `query` no longer REPLACES the question (finding 7): it is one more
 *    retrieval-only rephrasing.
 */
class Sprint13bConvenioSearchNormalizedTest extends TestCase
{
    use RefreshDatabase;

    private const LITERAL = 'Voy a casarme pronto, ¿me dan algún día libre?';

    private const CANONICAL = 'permiso retribuido por matrimonio';

    private Convenio $convenio;

    private Document $doc;

    private int $chunkId;

    /** @var list<array<string,mixed>> every /retrieve param set, in order */
    public array $retrieved = [];

    /** @var list<string> the question each /synthesise and /ground call received */
    public array $synthQuestions = [];

    /** @var list<string> */
    public array $groundQuestions = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DocumentTypeSeeder::class);
        $territory = Territory::create(['code' => '31', 'name' => 'Navarra', 'level' => 'provincial', 'aliases' => []]);
        $sector = Sector::create(['name' => 'Hostelería', 'aliases' => []]);
        $this->convenio = Convenio::create(['numero' => '13B-CS', 'name' => 'Convenio CS', 'territory_id' => $territory->id, 'sector_id' => $sector->id]);
        $this->doc = Document::create([
            'uuid' => (string) Str::uuid(), 'title' => 'Convenio (texto)', 'storage_path' => 'fake/'.Str::uuid(),
            'convenio_id' => $this->convenio->id, 'document_type_id' => DocumentType::where('code', 'convenio_text')->value('id'),
            'authority_level' => 'official_convenio', 'retrieval_status' => 'active', 'language' => 'es', 'tagging_status' => 'verified',
        ]);
        $this->chunkId = DB::table('document_chunks')->insertGetId([
            'document_id' => $this->doc->id, 'chunk_index' => 0, 'page_from' => 9, 'page_to' => 9,
            'content' => 'La persona trabajadora tendrá derecho a un permiso retribuido por matrimonio.',
            'token_count' => 12, 'convenio_id' => $this->convenio->id, 'retrieval_status' => 'active',
            'authority_level' => 'official_convenio', 'created_at' => now(), 'updated_at' => now(),
        ]);
        AnswerModelSetting::query()->delete();
        $s = new AnswerModelSetting(['provider' => 'claude']);
        $s->id = 1;
        $s->setKey('test-key-1234');
    }

    private function employee(): Employee
    {
        return Employee::create([
            'email' => 'cs13b-'.uniqid().'@example.com', 'full_name' => 'Convenio Search Normalized',
            'convenio_id' => $this->convenio->id, 'territory_id' => $this->convenio->territory_id,
            'employment_type' => 'full_time', 'status' => 'active',
        ]);
    }

    /** @param  callable(string):float  $scoreFor  the score the convenio-scoped pass gives the one chunk, per query (0 = nothing found) */
    private function bindAi(callable $scoreFor): void
    {
        $test = $this;
        $chunkId = $this->chunkId;
        $docId = $this->doc->id;
        $fake = new class($test, $scoreFor, $chunkId, $docId) extends ExtractionClient
        {
            public function __construct(private $test, private $scoreFor, private int $chunkId, private int $docId) {}

            public function retrieve(array $params): array
            {
                $this->test->recordRetrieve($params);
                $score = $params['convenio_id'] === null ? 0.0 : ($this->scoreFor)($params['query']);
                if ($score <= 0.0) {
                    return ['chunks' => [], 'eligible_total' => 0];
                }

                return ['chunks' => [[
                    'id' => $this->chunkId, 'document_id' => $this->docId, 'page_from' => 9, 'page_to' => 9,
                    'content' => 'La persona trabajadora tendrá derecho a un permiso retribuido por matrimonio.',
                    'score' => $score, 'authority_level' => 'official_convenio',
                ]], 'eligible_total' => 1];
            }

            public function synthesise(string $question, array $chunks, string $decryptedKey, array $providerConfig): array
            {
                $this->test->synthQuestions[] = $question;

                return [
                    'answer' => 'Tienes derecho a un permiso retribuido por matrimonio. [Fuente 1]',
                    'citations' => [['chunk_id' => $this->chunkId, 'document_id' => $this->docId, 'page_from' => 9, 'page_to' => 9, 'authority_level' => 'official_convenio']],
                    'grounding_signal' => ['grounded' => true, 'citation_count' => 1, 'top_chunk_score' => 0.9],
                    'confidence' => 0.9, 'authority_used' => ['official_convenio'], 'trace_fragment' => [],
                ];
            }

            public function ground(string $question, string $answer, array $chunks, string $decryptedKey, array $providerConfig): array
            {
                $this->test->groundQuestions[] = $question;

                return ['grounded' => true, 'claims' => [['claim' => 'permiso retribuido por matrimonio', 'grounded' => true, 'supporting_source' => $this->chunkId]], 'ungrounded' => [], 'trace_fragment' => []];
            }
        };
        $this->app->instance(ExtractionClient::class, $fake);
    }

    public function recordRetrieve(array $params): void
    {
        $this->retrieved[] = $params;
    }

    /** @param  list<array<string,mixed>>  $rounds */
    private function planner(array $rounds): void
    {
        $this->app->instance(PlannerClient::class, new class($rounds) implements PlannerClient
        {
            private int $i = 0;

            public function __construct(private array $rounds) {}

            public function plan(string $question, array $scopeSummary, array $window, array $toolDefinitions, array $priorSteps): array
            {
                $calls = $this->rounds[$this->i++] ?? [['id' => 'tx', 'tool' => 'finalize', 'input' => ['use' => ['t1']]]];

                return ['calls' => $calls, 'stop_reason' => 'tool_use', 'model' => 'm', 'request_id' => 'r', 'prompt_version' => 'sha256:test', 'tokens' => [], 'ms' => 0];
            }
        });
    }

    private function norm(): array
    {
        return ['id' => 'n1', 'tool' => 'normalize_question', 'input' => ['topic_id' => null, 'canonical_query' => self::CANONICAL, 'confidence' => 0.9, 'reason' => 'coloquial']];
    }

    private function search(array $input = []): array
    {
        return ['id' => 't1', 'tool' => 'convenio_search', 'input' => $input];
    }

    /** @return list<string> */
    private function queries(): array
    {
        return array_map(fn ($p) => $p['query'], array_values(array_filter($this->retrieved, fn ($p) => $p['convenio_id'] !== null)));
    }

    public function test_the_canonical_is_an_extra_pass_the_literal_stays_the_question_and_the_rescue_is_counted(): void
    {
        $this->bindAi(fn (string $q) => $q === self::CANONICAL ? 0.90 : 0.05);
        $this->planner([[$this->norm(), $this->search()]]);

        $r = app(AgentChatService::class)->handle($this->employee(), self::LITERAL);

        $this->assertSame('answer', $r['outcome']);
        $this->assertSame([self::LITERAL, self::CANONICAL], $this->queries(), 'literal first, canonical as the one extra pass');
        $this->assertSame(self::LITERAL, collect($this->retrieved)->firstWhere('convenio_id', null)['query'], 'the national-law pass keeps the literal question');
        $this->assertSame([self::LITERAL], $this->synthQuestions, '/synthesise answers the literal question');
        $this->assertSame([self::LITERAL], $this->groundQuestions, '/ground checks against the literal question');

        $n = $r['trace']['agent']['normalization'];
        $this->assertSame('accepted', $n['verdict']);
        $this->assertNull($n['used']['topic_id']);
        $this->assertSame(self::CANONICAL, $n['used']['canonical_query']);
        $c = $n['consumers'][0];
        $this->assertSame('convenio_search', $c['tool']);
        $this->assertSame('planner_call', $c['via']);
        $this->assertEqualsWithDelta(0.90, $c['canonical_top_score'], 1e-6);
        $this->assertEqualsWithDelta(0.05, $c['literal_top_score'], 1e-6);
        $this->assertEqualsWithDelta(0.90, $c['union_top_score'], 1e-6);
        $this->assertTrue($c['check_a_rescued']);
        $this->assertTrue($c['rescued_answer'], 'counted and listed by the gate');
        $this->assertArrayHasKey('protect_main', $r['trace']['retrieval']['rerank']);
    }

    public function test_the_counterfactual_without_a_normalization_is_the_ordinary_check_a_escalation(): void
    {
        $this->bindAi(fn (string $q) => $q === self::CANONICAL ? 0.90 : 0.05);
        $this->planner([[$this->search()], [['id' => 'f1', 'tool' => 'finalize', 'input' => ['use' => ['t1']]]]]);

        $r = app(AgentChatService::class)->handle($this->employee(), self::LITERAL);

        $this->assertSame('escalate', $r['outcome']);
        $this->assertSame('low_confidence', $r['escalation_reason']);
        $this->assertFalse($r['trace']['floor_decision']['check_a_retrieval']);
        $this->assertSame([self::LITERAL], $this->queries());
        $this->assertSame([], $this->synthQuestions, 'no synthesis was spent');
        $this->assertSame('absent', $r['trace']['agent']['normalization']['verdict']);
    }

    public function test_a_planner_query_no_longer_replaces_the_question_it_is_one_more_retrieval_pass(): void
    {
        $this->bindAi(fn (string $q) => $q === 'permiso por boda' ? 0.90 : 0.05);
        $this->planner([[$this->search(['query' => 'permiso por boda'])]]);

        $r = app(AgentChatService::class)->handle($this->employee(), self::LITERAL);

        $this->assertSame([self::LITERAL, 'permiso por boda'], $this->queries());
        $this->assertSame([self::LITERAL], $this->synthQuestions, 'synthesis and grounding see what the employee wrote');
        $this->assertSame([self::LITERAL], $this->groundQuestions);
        $this->assertSame('answer', $r['outcome']);
        $this->assertSame([], $r['trace']['agent']['normalization']['consumers'], 'no normalization → nothing to count');
    }

    public function test_a_rejected_canonical_never_reaches_retrieval(): void
    {
        $this->bindAi(fn (string $q) => 0.05);
        $bad = ['id' => 'n1', 'tool' => 'normalize_question', 'input' => ['topic_id' => null, 'canonical_query' => 'permiso retribuido de 15 días por matrimonio', 'confidence' => 0.9, 'reason' => 'x']];
        $this->planner([[$bad, $this->search()], [['id' => 'f1', 'tool' => 'finalize', 'input' => ['use' => ['t1']]]]]);

        $r = app(AgentChatService::class)->handle($this->employee(), self::LITERAL);

        $this->assertSame('rejected', $r['trace']['agent']['normalization']['verdict']);
        $this->assertSame([self::LITERAL], $this->queries(), 'the rejected canonical is not searched');
        $this->assertSame('escalate', $r['outcome']);
    }
}
