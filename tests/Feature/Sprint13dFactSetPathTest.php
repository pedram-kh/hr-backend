<?php

namespace Tests\Feature;

use App\Models\AnswerModelSetting;
use App\Models\Convenio;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Employee;
use App\Models\ReferenceFact;
use App\Models\Sector;
use App\Models\Territory;
use App\Models\Topic;
use App\Services\ChatService;
use App\Services\ExtractionClient;
use Database\Seeders\DocumentTypeSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Slice 13d (ADR-0037) — P1–P6, the PATH half: how `ReferenceFactPath` answers a
 * complementary fact SET (Phase 1 bare quote, Phase 2 composition with governing
 * prose) and — P6 — that a SINGLE fact still builds byte-identical requests.
 */
class Sprint13dFactSetPathTest extends TestCase
{
    use RefreshDatabase;

    private const QUESTION = '¿Cuál es mi jornada máxima anual?';

    private const PROSE = 'La jornada máxima anual será de 1704 horas en 2025. En jornada continuada de más de 6 horas habrá un descanso de 15 minutos. Cada trabajador tendrá 2 días de libre disposición.';

    private Convenio $convenio;

    private Topic $topic;

    private Document $doc;

    private Employee $employee;

    /** @var list<array<string,mixed>> */
    private array $synthesisSources = [];

    /** @var list<array<string,mixed>> */
    private array $groundSources = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(DocumentTypeSeeder::class);

        $territory = Territory::create(['code' => '31', 'name' => 'Navarra', 'level' => 'provincial', 'aliases' => []]);
        $sector = Sector::create(['name' => 'Deporte', 'aliases' => []]);
        $this->convenio = Convenio::create(['numero' => '31000520', 'name' => 'Deporte', 'territory_id' => $territory->id, 'sector_id' => $sector->id]);
        $this->topic = Topic::firstOrCreate(['name' => 'jornada'], ['status' => 'approved']);
        $this->doc = Document::create([
            'title' => 'Convenio deporte', 'storage_path' => 'fake/c20.docx', 'convenio_id' => $this->convenio->id,
            'document_type_id' => DocumentType::query()->value('id'), 'authority_level' => 'official_convenio',
            'retrieval_status' => 'active', 'language' => 'es', 'tagging_status' => 'verified',
        ]);
        $this->employee = Employee::create([
            'email' => 'emp'.uniqid().'@example.com', 'full_name' => 'Empleada', 'convenio_id' => $this->convenio->id,
            'territory_id' => $territory->id, 'employment_type' => 'full_time', 'status' => 'active',
        ]);
    }

    // ---- P1 — Phase 1 --------------------------------------------------------

    public function test_p1_phase1_quotes_every_fact_in_order_with_its_citation_and_skips_the_model(): void
    {
        [$f140, $f143] = $this->pair();
        $this->bindAi([]); // exploding — no /synthesise, /ground, /retrieve

        $result = app(ChatService::class)->handleMessage($this->employee, self::QUESTION);

        $this->assertSame('answer', $result['outcome']);
        $this->assertSame('reference_fact', $result['trace']['floor_decision']['path']);
        $answer = $result['answer'];
        $this->assertStringContainsString('1704 horas', $answer);
        $this->assertStringContainsString('2 días de libre disposición', $answer);
        $this->assertLessThan(strpos($answer, '2 días de libre disposición'), strpos($answer, '1704 horas'));
        $this->assertCount(2, $result['citations']);
        $this->assertStringEndsWith('(p9)', $result['citations'][0]['snippet']);
        $this->assertStringEndsWith('(p10)', $result['citations'][1]['snippet']);
        $this->assertArrayNotHasKey('grounding', $result['trace']['floor_decision']);
        $this->assertSame($f140->id, $result['trace']['reference_fact']['fact_id']);
        $this->assertNotSame($f143->id, $result['trace']['reference_fact']['fact_id']);
    }

    // ---- P2 — Phase 2 --------------------------------------------------------

    public function test_p2_composition_sends_one_typed_source_per_fact_and_grounds_each_cited_fact_against_itself(): void
    {
        [$f140, $f143] = $this->pair();
        $this->composition(citeFactIds: [$f143->id, $f140->id]); // the model cites the second fact FIRST

        $result = app(ChatService::class)->handleMessage($this->employee, self::QUESTION);

        $this->assertSame('answer', $result['outcome']);
        $this->assertSame('reference_fact_composition', $result['trace']['floor_decision']['path']);

        // The request: governing chunk, then one reference_fact source per fact, in set order, verbatim.
        $factSources = array_values(array_filter($this->synthesisSources, fn ($s) => $s['source_type'] === 'reference_fact'));
        $this->assertCount(2, $factSources);
        $this->assertSame([$f140->id, $f143->id], array_column($factSources, 'fact_id'));
        $this->assertSame([$f140->value, $f143->value], array_column($factSources, 'content'));
        $this->assertSame(['chunk', 'reference_fact', 'reference_fact'], array_column($this->synthesisSources, 'source_type'));
        $this->assertNull($factSources[0]['chunk_id']);
        $this->assertSame('structured_reference', $factSources[0]['authority_level']);

        // /ground: each cited fact is entailed against ITS OWN value (model order: 143, then 140).
        $groundFacts = array_values(array_filter($this->groundSources, fn ($s) => $s['source_type'] === 'reference_fact'));
        $this->assertSame([$f143->value, $f140->value], array_column($groundFacts, 'content'));

        // Citations come out in the model's citation order, each resolved to ITS fact.
        $this->assertCount(3, $result['citations'], 'the governing chunk + one entry per cited fact');
        $this->assertNotNull($result['citations'][0]['chunk_id']);
        $this->assertStringEndsWith('(p10)', $result['citations'][1]['snippet']);
        $this->assertStringEndsWith('(p9)', $result['citations'][2]['snippet']);

        $comp = $result['trace']['composition'];
        $this->assertSame([$f140->id, $f143->id], $comp['fact_ids_offered']);
        $this->assertSame([$f143->id, $f140->id], $comp['fact_ids_cited']);
        $this->assertSame([$f140->id, $f143->id], $result['trace']['reference_fact']['fact_set']['facts_selected']);
    }

    // ---- P3 — one cited ------------------------------------------------------

    public function test_p3_a_model_that_cites_one_fact_yields_one_fact_citation(): void
    {
        [$f140] = $this->pair();
        $this->composition(citeFactIds: [$f140->id]);

        $result = app(ChatService::class)->handleMessage($this->employee, self::QUESTION);

        $this->assertSame('answer', $result['outcome']);
        $this->assertCount(2, $result['citations'], 'chunk + the one cited fact');
        $this->assertSame([$f140->id], $result['trace']['composition']['fact_ids_cited']);
        $groundFacts = array_values(array_filter($this->groundSources, fn ($s) => $s['source_type'] === 'reference_fact'));
        $this->assertSame([$f140->value], array_column($groundFacts, 'content'));
    }

    // ---- P4 — conflict on one member -----------------------------------------

    public function test_p4_a_fact_vs_prose_conflict_on_one_member_escalates_before_synthesis(): void
    {
        [$f140, $f143] = $this->pair();
        // The governing chunk states 8 horas where fact 143 states 6 horas on the same unit,
        // while still carrying fact 140's figures — only ONE member disagrees.
        $prose = 'La jornada máxima anual será de 1704 horas en 2025. En jornada continuada de más de 8 horas habrá un descanso.';
        $this->composition(citeFactIds: [$f140->id, $f143->id], prose: $prose, expectSynthesis: false);

        $result = app(ChatService::class)->handleMessage($this->employee, self::QUESTION);

        $this->assertSame('escalate', $result['outcome']);
        $this->assertSame('conflict', $result['escalation_reason']);
        $this->assertSame('reference_fact_composition', $result['trace']['floor_decision']['path']);
        $this->assertSame('hora', $result['trace']['composition']['conflict']['unit']);
        $this->assertSame($f143->id, $result['trace']['composition']['conflict']['fact_id'], 'the disagreeing member is named');
        $this->assertSame([], $this->synthesisSources, '/synthesise must never be called');
    }

    // ---- P5 — Check B --------------------------------------------------------

    public function test_p5_an_unknown_or_missing_fact_id_is_dropped_and_only_unknown_ids_fail_check_b(): void
    {
        [$f140] = $this->pair();

        // One valid + one hallucinated id: the valid one survives, the hallucinated one is dropped.
        $this->composition(citeFactIds: [$f140->id, 999999]);
        $result = app(ChatService::class)->handleMessage($this->employee, self::QUESTION);
        $this->assertSame('answer', $result['outcome']);
        $this->assertCount(2, $result['citations'], 'chunk + fact 140; the hallucinated id is dropped');
        $this->assertSame([$f140->id], $result['trace']['composition']['fact_ids_cited']);
    }

    public function test_p5b_only_unknown_ids_leave_no_valid_citation_so_the_turn_escalates(): void
    {
        $this->pair();
        // A hallucinated id, and a fact citation with NO id, and NO chunk citation to fall back on.
        $this->composition(citeFactIds: [999999, null], chunkCited: false);

        $result = app(ChatService::class)->handleMessage($this->employee, self::QUESTION);

        $this->assertSame('escalate', $result['outcome']);
        $this->assertSame('low_confidence', $result['escalation_reason']);
        $this->assertFalse($result['trace']['floor_decision']['check_b_citations']);
        $this->assertSame([], $result['trace']['composition']['fact_ids_cited']);
    }

    public function test_p5d_dropped_fact_citations_do_not_sink_a_turn_whose_chunk_citation_is_valid(): void
    {
        $this->pair();
        $this->composition(citeFactIds: [999999, null]); // chunk 13500 is cited too

        $result = app(ChatService::class)->handleMessage($this->employee, self::QUESTION);

        $this->assertSame('answer', $result['outcome']);
        $this->assertCount(1, $result['citations']);
        $this->assertNull($result['citations'][0]['is_reference_fact'] ?? null, 'only the convenio chunk survives');
        $this->assertSame([], $result['trace']['composition']['fact_ids_cited']);
    }

    public function test_p5c_the_same_fact_cited_twice_yields_one_citation(): void
    {
        [$f140] = $this->pair();
        $this->composition(citeFactIds: [$f140->id, $f140->id]);

        $result = app(ChatService::class)->handleMessage($this->employee, self::QUESTION);

        $this->assertCount(2, $result['citations'], 'chunk + fact 140 once');
    }

    // ---- P6 — a single fact is byte-identical --------------------------------

    public function test_p6_a_single_fact_composition_sends_no_fact_id_and_no_set_trace(): void
    {
        $fact = ReferenceFact::create($this->attrs(
            'Jornada anual a tiempo completo: 1704 horas de trabajo efectivo.', '2025-01-01', ['2025' => '1704 horas'], 'p9',
        ));
        $this->composition(citeFactIds: [null], singleFact: true);

        $result = app(ChatService::class)->handleMessage($this->employee, self::QUESTION);

        $this->assertSame('answer', $result['outcome']);
        $factSources = array_values(array_filter($this->synthesisSources, fn ($s) => $s['source_type'] === 'reference_fact'));
        $this->assertCount(1, $factSources);
        $this->assertSame(
            ['chunk_id', 'source_type', 'document_id', 'page_from', 'page_to', 'content', 'score', 'authority_level'],
            array_keys($factSources[0]),
            'the single-fact source has EXACTLY the pre-13d keys — no fact_id',
        );
        $this->assertArrayNotHasKey('fact_set', $result['trace']['reference_fact']);
        $this->assertArrayNotHasKey('fact_ids_offered', $result['trace']['composition']);
        $this->assertArrayNotHasKey('fact_ids_cited', $result['trace']['composition']);
        $this->assertSame($fact->id, $result['trace']['reference_fact']['fact_id']);
    }

    // ---- helpers ------------------------------------------------------------

    /** @return array{0: ReferenceFact, 1: ReferenceFact} */
    private function pair(): array
    {
        return [
            ReferenceFact::create($this->attrs(
                'Con carácter general: Año 2025: 1704 horas de trabajo efectivo; Año 2026: 1700 horas; Año 2027: 1696 horas; Año 2028: 1692 horas.',
                '2025-01-01', ['2025' => '1704 horas', '2026' => '1700 horas', '2027' => '1696 horas', '2028' => '1692 horas'], 'p9',
            )),
            ReferenceFact::create($this->attrs(
                'Reglas generales de jornada: en jornadas continuadas de más de 6 horas un descanso de 15 minutos; 2 días de libre disposición.',
                '2025-01-01', ['jornada_irregular' => '0%', 'dias_libre_disposicion' => '2 días', 'descanso_jornada_continuada' => '15 minutos'], 'p10',
            )),
        ];
    }

    /**
     * @param  array<mixed>  $raw
     * @return array<string,mixed>
     */
    private function attrs(string $value, string $start, array $raw, string $locator): array
    {
        return [
            'convenio_id' => $this->convenio->id, 'topic_id' => $this->topic->id, 'job_category_id' => null, 'group_label' => null,
            'value' => $value, 'raw_values' => $raw, 'authority_level' => 'structured_reference',
            'source' => 'ai_agent', 'status' => 'verified', 'validity_start' => $start,
            'source_document_id' => $this->doc->id, 'source_locator' => $locator,
        ];
    }

    /**
     * Bind a scripted hr-ai: one governing convenio chunk on the topic, a synthesise that cites the given
     * FACT ids (null = a fact citation without an id) after the chunk, and a ground that records its sources.
     *
     * @param  list<int|null>  $citeFactIds
     */
    private function composition(array $citeFactIds, string $prose = self::PROSE, bool $expectSynthesis = true, bool $singleFact = false, bool $chunkCited = true): void
    {
        AnswerModelSetting::query()->delete();
        $s = new AnswerModelSetting(['provider' => 'claude']);
        $s->id = 1;
        $s->setKey('test-key-1234');

        DB::table('document_chunks')->insert([
            'id' => 13500, 'document_id' => $this->doc->id, 'chunk_index' => 0, 'page_from' => 9, 'page_to' => 10,
            'content' => $prose, 'token_count' => 40, 'convenio_id' => $this->convenio->id, 'retrieval_status' => 'active',
            'authority_level' => 'official_convenio', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $docId = $this->doc->id;
        $self = $this;

        $fake = new class($prose, $docId, $citeFactIds, $expectSynthesis, $singleFact, $chunkCited, $self) extends ExtractionClient
        {
            public function __construct(
                private string $prose, private int $docId, private array $cite, private bool $expectSynthesis,
                private bool $singleFact, private bool $chunkCited, private Sprint13dFactSetPathTest $t,
            ) {}

            public function retrieve(array $params): array
            {
                return ($params['convenio_id'] ?? null) === null
                    ? ['chunks' => [], 'eligible_total' => 0]
                    : ['chunks' => [[
                        'id' => 13500, 'document_id' => $this->docId, 'page_from' => 9, 'page_to' => 10,
                        'content' => $this->prose, 'score' => 0.93, 'authority_level' => 'official_convenio',
                    ]], 'eligible_total' => 1];
            }

            public function route(string $q, string $k, array $c): array
            {
                throw new \RuntimeException('the reference-fact path must NOT call /route');
            }

            public function synthesise(string $question, array $chunks, string $decryptedKey, array $providerConfig): array
            {
                if (! $this->expectSynthesis) {
                    throw new \RuntimeException('/synthesise must not be called');
                }
                $this->t->recordSynthesis($chunks);
                $citations = $this->chunkCited
                    ? [['chunk_id' => 13500, 'source_type' => 'chunk', 'document_id' => $this->docId, 'page_from' => 9, 'page_to' => 10, 'authority_level' => 'official_convenio']]
                    : [];
                foreach ($this->cite as $factId) {
                    $c = ['chunk_id' => null, 'source_type' => 'reference_fact', 'document_id' => $this->docId, 'authority_level' => 'structured_reference'];
                    $citations[] = $factId === null ? $c : $c + ['fact_id' => $factId];
                }

                return [
                    'answer' => 'Tu jornada máxima anual es de 1704 horas [Fuente 1][Fuente 2].',
                    'confidence' => 0.92, 'authority_used' => ['official_convenio', 'structured_reference'],
                    'citations' => $citations, 'trace_fragment' => [],
                ];
            }

            public function ground(string $q, string $a, array $ch, string $k, array $c): array
            {
                $this->t->recordGround($ch);

                return ['grounded' => true, 'claims' => [['claim' => 'x', 'grounded' => true, 'supporting_source' => 1]], 'ungrounded' => [], 'trace_fragment' => []];
            }
        };
        $this->app->instance(ExtractionClient::class, $fake);
    }

    /** @param list<array<string,mixed>> $sources */
    public function recordSynthesis(array $sources): void
    {
        $this->synthesisSources = $sources;
    }

    /** @param list<array<string,mixed>> $sources */
    public function recordGround(array $sources): void
    {
        $this->groundSources = $sources;
    }

    /** @param  array<string,mixed>  $script */
    private function bindAi(array $script): void
    {
        $fake = new class extends ExtractionClient
        {
            public function __construct() {}

            public function retrieve(array $params): array
            {
                throw new \RuntimeException('Phase 1 must not retrieve');
            }

            public function route(string $q, string $k, array $c): array
            {
                throw new \RuntimeException('must NOT call /route');
            }

            public function synthesise(string $q, array $ch, string $k, array $c): array
            {
                throw new \RuntimeException('Phase 1 must NOT call /synthesise');
            }

            public function ground(string $q, string $a, array $ch, string $k, array $c): array
            {
                throw new \RuntimeException('Phase 1 must NOT call /ground');
            }
        };
        $this->app->instance(ExtractionClient::class, $fake);
    }
}
