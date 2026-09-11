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
use App\Support\CorpusCoverageService;
use App\Support\EscalationExplainer;
use Database\Seeders\DocumentTypeSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Sprint 10a — the Estatuto-fallback invariant gate (plan §E.3 T1-T10, build
 * authorization T11-T12).
 *
 * The sprint's whole safety argument is a single distinction: "we never had
 * this convenio's text" is safe to answer from the national baseline; "we have
 * it but are not serving it" is not, because an expired convenio generally
 * stays in force under ultraactividad (ET art. 86.4) and the Estatuto minimum
 * can be strictly worse than what the employee is actually owed. Every test
 * here exists to stop that distinction eroding.
 *
 * Four convenios are built once and reused, one per state the classifier must
 * tell apart:
 *   - COVERED       — active prose document WITH chunks (nothing may change);
 *   - NEVER         — no prose document at all, no chunks (fallback fires);
 *   - EXPIRED       — historical prose document, chunks present but historical;
 *   - MID_INGEST    — ACTIVE prose document, zero chunks (D3, fails closed).
 */
class Sprint10aInvariantTest extends TestCase
{
    use RefreshDatabase;

    private const CHUNK_COVERED = 9101;

    private const CHUNK_ESTATUTO = 9102;

    private const CHUNK_EXPIRED = 9103;

    /** @var array<string,Convenio> */
    private array $convenios = [];

    /** @var array<string,Employee> */
    private array $employees = [];

    private Document $estatutoDoc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(DocumentTypeSeeder::class);
        $this->configureAnswerModel();
        $this->buildWorld();
        $this->bindFakeAi();
    }

    /**
     * Configure the answer model at id=1 (what `AnswerModelSetting::current()`
     * looks up). Forced explicitly because Postgres sequences are NOT rolled
     * back between test classes, so `firstOrCreate(['id'=>1])` would otherwise
     * create the row at an auto-increment id and `current()` would miss it —
     * and an unconfigured answer model escalates every prose turn, which would
     * make several of these tests pass for entirely the wrong reason.
     */
    private function configureAnswerModel(): void
    {
        AnswerModelSetting::query()->delete();
        $s = new AnswerModelSetting(['provider' => 'claude']);
        $s->id = 1;
        $s->setKey('test-key-1234');
    }

    // -- T1 -----------------------------------------------------------------

    public function test_t1_one_definition_of_truth_doc_predicate_agrees_with_the_coverage_grid(): void
    {
        $service = app(CorpusCoverageService::class);
        $grid = collect($service->grid())->keyBy('convenio_id');

        $this->assertNotEmpty($grid, 'fixture corpus produced an empty coverage grid');

        foreach ($grid as $convenioId => $row) {
            $this->assertSame(
                ! $row['prose']['covered'],
                $service->hasZeroProseChunks((int) $convenioId),
                "convenio {$convenioId}: hasZeroProseChunks() disagrees with the Cobertura grid — "
                .'a second definition of "full gap" has drifted into the codebase (plan R5)'
            );
        }
    }

    // -- T11 (build authorization) -------------------------------------------

    public function test_t11_doc_predicate_agrees_with_the_chunk_predicate_retrieve_actually_sees(): void
    {
        $service = app(CorpusCoverageService::class);

        foreach (Convenio::pluck('id') as $convenioId) {
            // What `/retrieve` sees: chunks carrying this convenio_id that are
            // themselves active (hr-ai/app/chunks_db.py filters on the chunk's
            // own denormalized columns, not on the parent document's).
            $retrievableChunks = DB::table('document_chunks')
                ->where('convenio_id', $convenioId)
                ->where('retrieval_status', 'active')
                ->count();

            $this->assertSame(
                $retrievableChunks === 0,
                $service->hasZeroProseChunks((int) $convenioId),
                "convenio {$convenioId}: the document-level predicate and the chunk-level reality "
                .'disagree. Drift between documents.retrieval_status and document_chunks.'
                .'retrieval_status makes the fallback fire on a convenio retrieval can still see, '
                .'or withhold it from one it cannot.'
            );
        }
    }

    // -- T2 / T10 -----------------------------------------------------------

    public function test_t2_a_covered_convenio_answers_with_no_fallback_key_at_all(): void
    {
        $result = $this->ask('covered', '¿Cuántos días de vacaciones tengo?');

        $this->assertSame('answer', $result['outcome']);
        $this->assertArrayNotHasKey(
            'fallback',
            $result['trace']['floor_decision'],
            'a covered convenio must produce a byte-identical floor_decision — the key is absent, not false'
        );
        $this->assertArrayNotHasKey('prose_gap', $result['trace']);
        $this->assertStringNotContainsString('Estatuto de los Trabajadores', $result['answer']);
    }

    // -- T3 -----------------------------------------------------------------

    public function test_t3_a_partial_gap_keeps_todays_behaviour_and_never_falls_back(): void
    {
        // Chunks exist for this convenio, they are simply silent on the topic:
        // retrieval returns nothing above the floor. That is the PRE-SPRINT
        // escalation and must stay exactly that — the fallback is for an absent
        // corpus, never for a corpus that merely lacks an answer.
        $result = $this->ask('covered', '¿Qué pasa con el teletrabajo internacional?', retrieveEmpty: true);

        $this->assertSame('escalate', $result['outcome']);
        $this->assertSame('low_confidence', $result['escalation_reason']);
        $this->assertArrayNotHasKey('fallback', $result['trace']['floor_decision']);
    }

    // -- T4 (the central decision) -------------------------------------------

    public function test_t4_an_expired_convenio_escalates_and_never_falls_back(): void
    {
        $result = $this->ask('expired', '¿Cuántos días de vacaciones tengo?');

        $this->assertSame('escalate', $result['outcome']);
        $this->assertSame('estatuto_fallback_gap', $result['escalation_reason']);
        $this->assertArrayNotHasKey(
            'fallback',
            $result['trace']['floor_decision'],
            'ultraactividad (ET 86.4): an expired convenio generally still applies, so the Estatuto '
            .'minimum must never be substituted for it'
        );
        $this->assertSame('expired_only', $result['trace']['prose_gap']['classification']);
        $this->assertSame(ChatService::EMPLOYEE_ESCALATION_MESSAGE, $result['answer']);
    }

    // -- T12 (build authorization, D3) ---------------------------------------

    public function test_t12_a_mid_ingest_convenio_fails_closed_and_never_falls_back(): void
    {
        $service = app(CorpusCoverageService::class);
        $midIngestId = (int) $this->convenios['mid_ingest']->id;

        $this->assertNotSame(
            CorpusCoverageService::PROSE_NEVER_INGESTED,
            $service->classifyProseGap($midIngestId),
            'an ACTIVE prose document with zero chunks is a document still being ingested, not a '
            .'convenio that was never supplied — classifying it as never_ingested would answer from '
            .'the Estatuto in the window between upload and embed'
        );

        $result = $this->ask('mid_ingest', '¿Cuántos días de vacaciones tengo?');

        $this->assertSame('escalate', $result['outcome']);
        $this->assertSame('estatuto_fallback_gap', $result['escalation_reason']);
        $this->assertArrayNotHasKey('fallback', $result['trace']['floor_decision']);
        $this->assertSame('not_yet_embedded', EscalationExplainer::explain(
            'estatuto_fallback_gap', $result['trace']
        )['sub_outcome']);
    }

    // -- the positive path: T5 / T6 -------------------------------------------

    public function test_t5_the_caveat_is_on_the_persisted_message_row_not_just_the_api_payload(): void
    {
        $result = $this->ask('never', '¿Cuántos días de vacaciones tengo?');

        $this->assertSame('answer', $result['outcome']);
        $this->assertSame(ChatService::FALLBACK_ESTATUTO_GAP, $result['trace']['floor_decision']['fallback']);

        // The persisted row is what an employee sees on reload and what HR sees
        // in the admin trace. A caveat that exists only in the response payload
        // would silently disappear from both.
        $persisted = DB::table('chat_messages')->where('id', $result['message_id'])->value('content');
        $this->assertStringContainsString(ChatService::FALLBACK_CAVEAT, $persisted);
        $this->assertStringContainsString('mínimos legales', $persisted);
        $this->assertSame($result['answer'], $persisted);
    }

    public function test_t5b_the_caveat_is_appended_after_the_synthesised_answer_not_woven_into_it(): void
    {
        $result = $this->ask('never', '¿Cuántos días de vacaciones tengo?');

        // Deterministic append, not model output: the answer the gates ran on is
        // still there, verbatim, as a prefix (ADR-0015/0016).
        $this->assertStringStartsWith('Según el Estatuto', $result['answer']);
        $this->assertStringEndsWith(ChatService::FALLBACK_CAVEAT, $result['answer']);
    }

    public function test_t6_a_fallback_answer_is_built_from_national_law_alone(): void
    {
        $result = $this->ask('never', '¿Cuántos días de vacaciones tengo?');

        $this->assertSame(['national_law'], $result['trace']['floor_decision']['authority_used']);

        foreach ($result['trace']['retrieval']['chunks'] as $chunk) {
            $this->assertSame(
                'national_law',
                $chunk['authority_level'],
                'no convenio or structured-reference chunk may reach synthesis on the fallback path'
            );
        }

        // The precedence re-rank is skipped, and says so: with no convenio side
        // there is nothing to adjudicate, and a rerank block would imply there was.
        $this->assertArrayHasKey('skipped', $result['trace']['retrieval']['rerank']);
    }

    // -- T7 -------------------------------------------------------------------

    public function test_t7_a_salary_question_from_a_full_gap_employee_never_reaches_the_fallback(): void
    {
        $result = $this->ask('never', '¿Cuál es mi salario base?', route: 'salary');

        $this->assertSame('escalate', $result['outcome']);
        $this->assertSame('salary_coverage_gap', $result['escalation_reason']);
        $this->assertArrayNotHasKey(
            'fallback',
            $result['trace']['floor_decision'],
            'the fallback lives on the prose path only — the Estatuto sets no salary figures'
        );
    }

    // -- T8 -------------------------------------------------------------------

    /**
     * Authority precedence does not depend on prose existing. A full-gap convenio
     * that happens to carry a verified reference fact must answer from the FACT —
     * `structured_reference` outranks `national_law` — and the Estatuto must not
     * get a look in, even though every condition for the fallback is met.
     *
     * The test is only meaningful because the convenio genuinely still classifies
     * as `never_ingested`, asserted below: without that, this would just be a
     * reference-fact test wearing a Sprint 10a label. The fact's source document
     * is a `reference_source`, which is deliberately NOT in
     * `KnowledgeMap::PROSE_TYPE_CODES` — attaching a prose document here would
     * flip the classification and quietly hollow out the test.
     */
    public function test_t8_a_verified_fact_outranks_the_estatuto_even_on_a_full_gap_convenio(): void
    {
        $convenio = $this->convenios['never'];

        $topic = Topic::firstOrCreate(['name' => 'periodo de prueba'], ['status' => 'approved']);
        // `structured_reference` is expressible on a FACT, not on a document —
        // `documents.authority_level` is the three-value legal ladder. The
        // load-bearing part here is the document TYPE, which keeps this out of
        // the prose set.
        $sourceDoc = $this->document(
            'Periodos de prueba (referencia)',
            (int) DocumentType::where('code', 'reference_source')->value('id'),
            'official_convenio',
            $convenio->id,
            'active',
        );
        $this->assertNotContains(
            (int) $sourceDoc->document_type_id,
            \App\Support\KnowledgeMap::proseTypeIds(),
            'the fact source must not be a prose type, or the fixture stops being a full gap'
        );
        ReferenceFact::create([
            'convenio_id' => $convenio->id,
            'topic_id' => $topic->id,
            'job_category_id' => null,
            'group_label' => null,
            'value' => 'periodo de prueba: 45 días',
            'authority_level' => 'structured_reference',
            'source' => 'admin_manual',
            'status' => 'verified',
            'validity_start' => null,
            'validity_end' => null,
            'source_document_id' => $sourceDoc->id,
            'source_locator' => 'p.1 §1',
        ]);

        // The premise: this convenio is still a full gap, so the fallback is armed.
        $this->assertSame(
            CorpusCoverageService::PROSE_NEVER_INGESTED,
            app(CorpusCoverageService::class)->classifyProseGap((int) $convenio->id),
            'the fixture must still be a full gap, or this test proves nothing about precedence'
        );

        $result = $this->ask('never', '¿cuál es mi periodo de prueba?');

        $this->assertSame('answer', $result['outcome']);
        $this->assertSame('reference_fact', $result['trace']['floor_decision']['path']);
        $this->assertSame(['structured_reference'], $result['trace']['floor_decision']['authority_used']);
        $this->assertStringContainsString('45 días', $result['answer']);

        // Neither the Estatuto's content nor the fallback's machinery appears: the
        // pre-check returns before `answerProse()` is ever entered, so there is no
        // fallback decision to stamp and no caveat to append.
        $this->assertStringNotContainsString('treinta días', $result['answer']);
        $this->assertStringNotContainsString('mínimos legales', $result['answer']);
        $this->assertArrayNotHasKey('fallback', $result['trace']['floor_decision']);
        $this->assertArrayNotHasKey('prose_gap', $result['trace']);
    }

    // -- T9 -------------------------------------------------------------------

    public function test_t9_every_new_escalation_reason_is_explainable(): void
    {
        $keys = array_values(array_filter(
            EscalationExplainer::MATRIX,
            fn (string $k) => str_starts_with($k, 'estatuto_fallback_gap.')
        ));

        $this->assertNotEmpty($keys);
        foreach ($keys as $key) {
            $this->assertTrue(
                EscalationExplainer::registryHasEntry($key),
                "{$key} has no explanation builder — HR would get a card with no stated cause or fix"
            );
        }
    }

    public function test_t9b_the_employee_never_learns_which_gap_caused_the_escalation(): void
    {
        $result = $this->ask('expired', '¿Cuántos días de vacaciones tengo?');

        foreach (['convenio', 'Estatuto', 'vencido', 'estatuto_fallback_gap', 'ultraactividad'] as $leak) {
            $this->assertStringNotContainsStringIgnoringCase($leak, $result['answer']);
        }
    }

    // -- helpers ---------------------------------------------------------------

    private function buildWorld(): void
    {
        $territory = Territory::create(['code' => '31', 'name' => 'Navarra', 'level' => 'provincial', 'aliases' => []]);
        $sector = Sector::create(['name' => 'Hostelería', 'aliases' => []]);

        $proseTypeId = (int) DocumentType::where('code', 'convenio_text')->value('id');
        $nationalTypeId = (int) DocumentType::where('code', 'national_law')->value('id');

        foreach (['covered', 'never', 'expired', 'mid_ingest'] as $i => $key) {
            $this->convenios[$key] = Convenio::create([
                'numero' => '31TEST000'.$i,
                'name' => 'Convenio '.$key,
                'territory_id' => $territory->id,
                'sector_id' => $sector->id,
            ]);
            $this->employees[$key] = Employee::create([
                'email' => "test-{$key}@example.com",
                'full_name' => 'Worker '.$key,
                'convenio_id' => $this->convenios[$key]->id,
                'territory_id' => $territory->id,
                'employment_type' => 'full_time',
                'status' => 'active',
            ]);
        }

        // COVERED — active prose document with a chunk.
        $coveredDoc = $this->document('Convenio Hostelería', $proseTypeId, 'official_convenio', $this->convenios['covered']->id, 'active');
        $this->chunk(self::CHUNK_COVERED, $coveredDoc->id, $this->convenios['covered']->id, 'official_convenio', 'active', 'Las vacaciones son de 31 días naturales.');

        // EXPIRED — historical prose document; its chunks exist but are historical.
        // This is convenios 4 and 21 on staging: the text is right there, it is
        // simply out of validity, and nobody has sourced the successor yet.
        $expiredDoc = $this->document('Convenio vencido', $proseTypeId, 'official_convenio', $this->convenios['expired']->id, 'historical');
        $this->chunk(self::CHUNK_EXPIRED, $expiredDoc->id, $this->convenios['expired']->id, 'official_convenio', 'historical', 'Las vacaciones eran de 32 días naturales.');

        // MID_INGEST (D3) — ACTIVE prose document, no chunks yet.
        $this->document('Convenio recién subido', $proseTypeId, 'official_convenio', $this->convenios['mid_ingest']->id, 'active');

        // NEVER — deliberately nothing at all for this convenio.

        // The Estatuto: national law, no convenio_id, always active.
        $this->estatutoDoc = $this->document('Estatuto de los Trabajadores', $nationalTypeId, 'national_law', null, 'active');
        $this->chunk(self::CHUNK_ESTATUTO, $this->estatutoDoc->id, null, 'national_law', 'active', 'Artículo 38. El periodo de vacaciones anuales no será inferior a treinta días naturales.');
    }

    private function document(string $title, int $typeId, string $authority, ?int $convenioId, string $status): Document
    {
        return Document::create([
            'title' => $title,
            'storage_path' => 'fake/'.uniqid(),
            'convenio_id' => $convenioId,
            'document_type_id' => $typeId,
            'authority_level' => $authority,
            'retrieval_status' => $status,
            'language' => 'es',
            'tagging_status' => 'verified',
        ]);
    }

    private function chunk(int $id, int $documentId, ?int $convenioId, string $authority, string $status, string $content): void
    {
        DB::table('document_chunks')->insert([
            'id' => $id, 'document_id' => $documentId, 'chunk_index' => 0,
            'page_from' => 1, 'page_to' => 1, 'content' => $content, 'token_count' => 12,
            'convenio_id' => $convenioId, 'retrieval_status' => $status,
            'authority_level' => $authority, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * Ask one question as the given fixture employee.
     *
     * @return array<string,mixed>
     */
    private function ask(string $who, string $question, bool $retrieveEmpty = false, string $route = 'prose'): array
    {
        $this->bindFakeAi($retrieveEmpty, $route);

        return app(ChatService::class)->handleMessage($this->employees[$who], $question);
    }

    /**
     * Script hr-ai deterministically (no network). `/retrieve` always answers
     * with the Estatuto chunk — which is the point: the fallback must be decided
     * by hr-backend's DB read, NOT by whether retrieval happens to return
     * something. A test that let retrieval decide would pass for the wrong reason.
     */
    private function bindFakeAi(bool $retrieveEmpty = false, string $route = 'prose'): void
    {
        $estatutoChunkId = self::CHUNK_ESTATUTO;
        $estatutoDocId = $this->estatutoDoc->id;
        $coveredChunkId = self::CHUNK_COVERED;

        $fake = new class($retrieveEmpty, $route, $estatutoChunkId, $estatutoDocId, $coveredChunkId) extends ExtractionClient
        {
            public function __construct(
                private bool $retrieveEmpty,
                private string $route,
                private int $estatutoChunkId,
                private int $estatutoDocId,
                private int $coveredChunkId,
            ) {}

            public function route(string $question, string $decryptedKey, array $providerConfig): array
            {
                return ['label' => $this->route, 'confidence' => 0.95, 'subqueries' => [], 'reason' => null, 'trace_fragment' => []];
            }

            public function retrieve(array $params): array
            {
                if ($this->retrieveEmpty) {
                    return ['eligible_total' => 0, 'chunks' => []];
                }

                // The Estatuto chunk comes back on EVERY pass — including the
                // scoped ones — exactly as production does with
                // `include_national_law: true`. The covered convenio's own chunk
                // comes back only on a scoped pass, which is what makes the
                // national-law-only assertion in T6 meaningful.
                $chunks = [[
                    'id' => $this->estatutoChunkId,
                    'document_id' => $this->estatutoDocId,
                    'page_from' => 38, 'page_to' => 38, 'score' => 0.88,
                    'authority_level' => 'national_law',
                    'content' => 'Artículo 38. El periodo de vacaciones anuales no será inferior a treinta días naturales.',
                ]];

                if (($params['convenio_id'] ?? null) !== null) {
                    array_unshift($chunks, [
                        'id' => $this->coveredChunkId,
                        'document_id' => $this->estatutoDocId,
                        'page_from' => 9, 'page_to' => 9, 'score' => 0.92,
                        'authority_level' => 'official_convenio',
                        'content' => 'Las vacaciones son de 31 días naturales.',
                    ]);
                }

                return ['eligible_total' => count($chunks), 'chunks' => $chunks];
            }

            public function synthesise(string $question, array $chunks, string $decryptedKey, array $providerConfig): array
            {
                $first = $chunks[0] ?? [];
                $isNational = ($first['authority_level'] ?? null) === 'national_law';

                return [
                    'answer' => $isNational
                        ? 'Según el Estatuto, el periodo de vacaciones anuales no será inferior a treinta días naturales. [Fuente 1]'
                        : 'Según tu convenio, las vacaciones son de 31 días naturales. [Fuente 1]',
                    'citations' => [[
                        'chunk_id' => $first['chunk_id'] ?? null,
                        'document_id' => $first['document_id'] ?? null,
                        'page_from' => $first['page_from'] ?? 1,
                        'page_to' => $first['page_to'] ?? 1,
                        'authority_level' => $first['authority_level'] ?? 'national_law',
                    ]],
                    'grounding_signal' => ['grounded' => true, 'citation_count' => 1, 'top_chunk_score' => 0.92],
                    'confidence' => 0.93,
                    'authority_used' => [$first['authority_level'] ?? 'national_law'],
                    'trace_fragment' => [],
                ];
            }

            public function ground(string $question, string $answer, array $chunks, string $decryptedKey, array $providerConfig): array
            {
                return ['grounded' => true, 'claims' => [], 'ungrounded' => [], 'error' => null, 'trace_fragment' => []];
            }
        };

        $this->app->instance(ExtractionClient::class, $fake);
    }
}
