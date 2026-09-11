<?php

namespace Tests\Feature;

use App\Models\AnswerModelSetting;
use App\Models\Convenio;
use App\Models\ConvenioJobCategory;
use App\Models\Document;
use App\Models\Employee;
use App\Models\MessageTrace;
use App\Models\SalaryTable;
use App\Models\SalaryTableRow;
use App\Models\Sector;
use App\Models\Territory;
use App\Services\ChatService;
use App\Services\ExtractionClient;
use Database\Seeders\DocumentTypeSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sprint 7c — the GOLDEN-TRACE additivity gate (the load-bearing discipline).
 *
 * 7c is the ONE sprint that touches the frozen 2b answer loop. The whole
 * discipline is that it is PURELY ADDITIVE: a question that needs neither a
 * reference fact nor composition must get the BYTE-FOR-BYTE identical answer +
 * trace it got pre-7c. This test pins that contract.
 *
 * It scripts hr-ai (route / retrieve / synthesise / ground) deterministically so
 * the prose + salary turns are fully reproducible, then asserts the answer text,
 * the citations, and the load-bearing trace blocks (router_decision, retrieval
 * passes + rerank, floor_decision, authority_used). Both turns use a scope/topic
 * with NO verified reference fact, so the 7c pre-check PROVABLY falls through —
 * asserted by the ABSENCE of any `reference_fact` trace block.
 *
 * Captured GREEN against pre-7c code (the baseline), this test is the commit gate
 * for Phase 1 and is re-run as the Phase 2 gate. If it ever diverges, the change
 * stopped being additive — STOP.
 */
class Sprint7cAdditivityRegressionTest extends TestCase
{
    use RefreshDatabase;

    private Convenio $convenio;

    private Employee $employee;

    private ConvenioJobCategory $category;

    private Document $proseDoc;

    private Document $estatutoDoc;

    private Document $salaryDoc;

    /** The convenio vacaciones chunk id (governing) — must win over the Estatuto. */
    private const PROSE_CONVENIO_CHUNK_ID = 9001;

    /** The Estatuto (national_law) vacaciones chunk id — the baseline, never cited here. */
    private const PROSE_NATIONAL_CHUNK_ID = 9002;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(DocumentTypeSeeder::class);

        $territory = Territory::create(['code' => '31', 'name' => 'Navarra', 'level' => 'provincial', 'aliases' => []]);
        $sector = Sector::create(['name' => 'Hostelería', 'aliases' => []]);
        $this->convenio = Convenio::create([
            'numero' => '31000505', 'name' => 'Hostelería de Navarra',
            'territory_id' => $territory->id, 'sector_id' => $sector->id,
        ]);
        $this->category = ConvenioJobCategory::create([
            'convenio_id' => $this->convenio->id, 'name' => 'Peón', 'group_code' => '1',
        ]);
        $this->employee = Employee::create([
            'email' => 'emp@example.com', 'full_name' => 'Empleada Navarra',
            'convenio_id' => $this->convenio->id, 'job_category_id' => $this->category->id,
            'territory_id' => $territory->id, 'employment_type' => 'full_time', 'status' => 'active',
        ]);

        // The governing convenio prose document the vacaciones answer must cite.
        $this->proseDoc = $this->doc('Convenio Hostelería Navarra', 'official_convenio');
        // The Estatuto baseline — present in retrieval, must NOT be cited for a convenio-governed topic.
        $this->estatutoDoc = $this->doc('Estatuto de los Trabajadores', 'national_law', convenio: false, typeCode: 'national_law');
        // The salary-table source document the salary citation points at (chunk_id = null).
        $this->salaryDoc = $this->doc('Tablas salariales 2026', 'official_convenio', typeCode: 'salary_tables');

        // Real chunk rows so the prose citation FK (message_citations.chunk_id) resolves.
        $this->chunkRow(self::PROSE_CONVENIO_CHUNK_ID, $this->proseDoc->id, 'official_convenio');
        $this->chunkRow(self::PROSE_NATIONAL_CHUNK_ID, $this->estatutoDoc->id, 'national_law');

        // Salary table for the current year (the question date year) + a row for the category.
        $table = SalaryTable::create([
            'convenio_id' => $this->convenio->id, 'year' => (int) now()->year,
            'source_document_id' => $this->salaryDoc->id,
        ]);
        SalaryTableRow::create([
            'salary_table_id' => $table->id, 'job_category_id' => $this->category->id,
            // Both figures are STORED, from a source that states both — which is
            // why Correction-salary-01 leaves this pinned turn byte-for-byte
            // unchanged: it only ever removed DERIVED figures.
            'gross_annual' => 21000, 'base_salary_monthly' => 1500, 'pagas_count' => 14,
        ]);

        $this->configureAnswerModel();
        $this->bindFakeAi();
    }

    /**
     * The PROSE turn (Navarra vacaciones → "37 días laborables" cited to the
     * convenio chunk, never the Estatuto) is byte-for-byte unchanged, and the
     * 7c pre-check provably falls through (no `reference_fact` trace block).
     */
    public function test_prose_turn_is_byte_for_byte_unchanged_and_precheck_falls_through(): void
    {
        $result = $this->chat()->handleMessage($this->employee, '¿cuántos días de vacaciones me corresponden?');

        // The answer + citation are exactly the scripted convenio answer.
        $this->assertSame('answer', $result['outcome']);
        $this->assertFalse($result['escalated']);
        $this->assertSame('Según tu convenio, las vacaciones son de 37 días laborables. [Fuente 1]', $result['answer']);
        $this->assertCount(1, $result['citations']);
        $this->assertSame(self::PROSE_CONVENIO_CHUNK_ID, $result['citations'][0]['chunk_id']);
        $this->assertSame($this->proseDoc->id, $result['citations'][0]['document_id']);
        $this->assertSame('official_convenio', $result['citations'][0]['authority_level']);

        $trace = $this->trace($result);

        // The prose path took its normal shape: router → retrieve union + rerank → A∧B → ground.
        $this->assertSame('prose', $trace['router_decision']['label']);
        $this->assertSame('llm', $trace['router_decision']['source']);
        $this->assertArrayHasKey('retrieval', $trace);
        $this->assertArrayHasKey('passes', $trace['retrieval']);
        $this->assertArrayHasKey('rerank', $trace['retrieval']);

        $floor = $trace['floor_decision'];
        $this->assertArrayNotHasKey('path', $floor, 'a prose turn never sets a structured floor_decision.path');
        $this->assertSame('answer', $floor['outcome']);
        $this->assertTrue($floor['check_a_retrieval']);
        $this->assertTrue($floor['check_b_citations']);
        $this->assertTrue($floor['grounding']['checked']);
        $this->assertTrue($floor['grounding']['grounded']);
        $this->assertSame(['official_convenio'], $floor['authority_used']);

        // THE ADDITIVITY ASSERTION: no reference-fact branch was taken.
        $this->assertArrayNotHasKey('reference_fact', $trace, 'the 7c pre-check must fall through — no reference_fact block');
    }

    /**
     * The SALARY turn (exact figure, chunk_id=null salary citation,
     * floor_decision.path:"salary_sql") is byte-for-byte unchanged, and the 7c
     * pre-check never runs (salary wins first — no `reference_fact` block).
     */
    public function test_salary_turn_is_byte_for_byte_unchanged_and_precheck_never_runs(): void
    {
        $result = $this->chat()->handleMessage($this->employee, '¿cuánto gana un peón?');

        $this->assertSame('answer', $result['outcome']);
        $this->assertFalse($result['escalated']);
        $this->assertStringContainsString('21.000,00 €', $result['answer']);
        $this->assertCount(1, $result['citations']);
        $this->assertNull($result['citations'][0]['chunk_id'], 'salary cites the source document with chunk_id = null');
        $this->assertTrue($result['citations'][0]['is_salary_table']);
        $this->assertSame($this->salaryDoc->id, $result['citations'][0]['document_id']);

        $trace = $this->trace($result);
        $this->assertSame('salary', $trace['router_decision']['label']);
        $this->assertSame('deterministic_salary', $trace['router_decision']['source']);
        $this->assertSame('salary_sql', $trace['floor_decision']['path']);
        $this->assertSame('answer', $trace['floor_decision']['outcome']);

        $this->assertArrayHasKey('salary', $trace);
        $this->assertArrayNotHasKey('reference_fact', $trace, 'salary wins first — the 7c pre-check never runs');
    }

    /**
     * Correction-salary-01, the deliberate RE-BASELINE of this pinned answer.
     *
     * The turn above is byte-for-byte unchanged by the correction, because its
     * fixture STORES both figures (a source that prints an annual and a monthly
     * — Deporte Cantabria, and the two OCR'd Gipuzkoa tables). That is the point
     * of the correction, not an escape from it: only DERIVED figures went away.
     *
     * This second turn pins the shape that did change. Where the source states
     * an annual and no monthly, the old code answered "salario base mensual de
     * 1.500,00 € en 14 pagas" (21.000 / 14, a figure no source printed); it now
     * states the annual and omits the monthly. The convenio that made this
     * visible is 15, whose live answer moved 2.392,24 € → 2.232,75 € — a
     * correctness re-baseline, not drift (ADR-0006, ADR-0027).
     */
    public function test_a_source_with_no_monthly_column_yields_an_annual_only_answer(): void
    {
        SalaryTableRow::where('job_category_id', $this->category->id)
            ->update(['base_salary_monthly' => null, 'pagas_count' => null]);

        $result = $this->chat()->handleMessage($this->employee, '¿cuánto gana un peón?');

        $this->assertSame('answer', $result['outcome']);
        $this->assertStringContainsString('bruto anual de 21.000,00 €', $result['answer']);
        $this->assertStringNotContainsString('1.500,00', $result['answer'], 'the pre-correction derived monthly');
        $this->assertStringNotContainsString('mensual', $result['answer']);

        $trace = $this->trace($result);
        $this->assertSame('salary_sql', $trace['floor_decision']['path'], 'still the SQL path — the answer is narrower, not weaker');
        $this->assertNull($trace['salary']['row']['base_salary_monthly']);
    }

    // ---- helpers ------------------------------------------------------------

    private function chat(): ChatService
    {
        return app(ChatService::class);
    }

    /**
     * Configure the answer model at id=1 (what AnswerModelSetting::current()
     * looks up). Forced explicitly because Postgres sequences are NOT rolled back
     * between test classes, so firstOrCreate(['id'=>1]) (id is not fillable) would
     * otherwise create the row at an auto-increment id and current() would miss it.
     */
    private function configureAnswerModel(): void
    {
        AnswerModelSetting::query()->delete();
        $s = new AnswerModelSetting(['provider' => 'claude']);
        $s->id = 1;
        $s->setKey('test-key-1234');
    }

    private function trace(array $result): array
    {
        return MessageTrace::firstOrFail()->trace;
    }

    private function chunkRow(int $id, int $documentId, string $authority): void
    {
        \Illuminate\Support\Facades\DB::table('document_chunks')->insert([
            'id' => $id, 'document_id' => $documentId, 'chunk_index' => 0,
            'page_from' => 9, 'page_to' => 9, 'content' => 'fixture chunk', 'token_count' => 10,
            'convenio_id' => $this->convenio->id, 'retrieval_status' => 'active',
            'authority_level' => $authority, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * @param  string  $authority  the document's `authority_level`.
     * @param  string  $typeCode  its `document_types.code`. Previously this
     *   helper took whatever `DocumentType::query()->value('id')` returned
     *   first, which is an arbitrary type and, for the prose documents, not a
     *   prose one. Nothing read `document_type_id` until Sprint 10a's prose-gap
     *   classifier did, at which point the fixture described a convenio holding
     *   chunks but owning no prose DOCUMENT — a state production cannot reach.
     *   Naming the type explicitly makes each fixture document what it claims to
     *   be; no asserted answer, citation or trace value depends on it.
     */
    private function doc(string $title, string $authority, bool $convenio = true, string $typeCode = 'convenio_text'): Document
    {
        return Document::create([
            'title' => $title,
            'storage_path' => 'fake/'.uniqid(),
            'convenio_id' => $convenio ? $this->convenio->id : null,
            'document_type_id' => \App\Models\DocumentType::where('code', $typeCode)->value('id'),
            'authority_level' => $authority,
            'retrieval_status' => 'active',
            'language' => 'es',
            'tagging_status' => 'verified',
        ]);
    }

    /**
     * Bind a scripted ExtractionClient so the prose + salary turns are fully
     * deterministic (no network). The same instance serves ChatService,
     * RouterService, and GroundingService (all depend on ExtractionClient).
     */
    private function bindFakeAi(): void
    {
        $proseChunkId = self::PROSE_CONVENIO_CHUNK_ID;
        $nlChunkId = self::PROSE_NATIONAL_CHUNK_ID;
        $proseDocId = $this->proseDoc->id;
        $estatutoDocId = $this->estatutoDoc->id;

        $fake = new class($proseChunkId, $nlChunkId, $proseDocId, $estatutoDocId) extends ExtractionClient
        {
            public function __construct(
                private int $proseChunkId,
                private int $nlChunkId,
                private int $proseDocId,
                private int $estatutoDocId,
            ) {}

            public function route(string $question, string $decryptedKey, array $providerConfig): array
            {
                return ['label' => 'prose', 'confidence' => 0.95, 'subqueries' => [], 'reason' => null, 'trace_fragment' => []];
            }

            public function retrieve(array $params): array
            {
                // A governing convenio vacaciones chunk (low raw score) + the
                // Estatuto baseline (higher raw score) — the precedence re-rank
                // must lift the convenio chunk above the baseline.
                return [
                    'eligible_total' => 2,
                    'chunks' => [
                        [
                            'id' => $this->proseChunkId, 'document_id' => $this->proseDocId,
                            'page_from' => 9, 'page_to' => 9, 'score' => 0.80,
                            'authority_level' => 'official_convenio',
                            'content' => 'Las vacaciones anuales serán de 37 días laborables para todo el personal.',
                        ],
                        [
                            'id' => $this->nlChunkId, 'document_id' => $this->estatutoDocId,
                            'page_from' => 38, 'page_to' => 38, 'score' => 0.85,
                            'authority_level' => 'national_law',
                            'content' => 'El periodo de vacaciones anuales será de 30 días naturales.',
                        ],
                    ],
                ];
            }

            public function synthesise(string $question, array $chunks, string $decryptedKey, array $providerConfig): array
            {
                return [
                    'answer' => 'Según tu convenio, las vacaciones son de 37 días laborables. [Fuente 1]',
                    'citations' => [[
                        'chunk_id' => $this->proseChunkId, 'document_id' => $this->proseDocId,
                        'page_from' => 9, 'page_to' => 9, 'authority_level' => 'official_convenio',
                    ]],
                    'grounding_signal' => ['grounded' => true, 'citation_count' => 1, 'top_chunk_score' => 0.85],
                    'confidence' => 0.92,
                    'authority_used' => ['official_convenio'],
                    'trace_fragment' => [],
                ];
            }

            public function ground(string $question, string $answer, array $chunks, string $decryptedKey, array $providerConfig): array
            {
                return [
                    'grounded' => true,
                    'claims' => [['claim' => '37 días laborables', 'grounded' => true, 'supporting_source' => $this->proseChunkId]],
                    'ungrounded' => [],
                    'trace_fragment' => [],
                ];
            }
        };

        $this->app->instance(ExtractionClient::class, $fake);
    }
}
