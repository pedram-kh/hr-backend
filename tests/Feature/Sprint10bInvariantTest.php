<?php

namespace Tests\Feature;

use App\Models\AnswerModelSetting;
use App\Models\Convenio;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Employee;
use App\Models\Sector;
use App\Models\Territory;
use App\Services\ChatService;
use App\Services\ExtractionClient;
use App\Support\EscalationExplainer;
use Database\Seeders\DocumentTypeSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Sprint 10b — invariant gate (plan §E.4, build authorization T-D2/T-superset).
 *
 * Three independent, additive features share this fixture: the deterministic
 * `explicit_request` pre-check (§C.6, D2/D3), the colloquial salary-lexicon
 * addition (§C.1, D1), and the `decomposed_queries` retrieval-union join (§B,
 * D4). None of them may change behavior for a question they don't touch — the
 * whole sprint's premise is additivity (golden-trace regression stays green;
 * `Sprint7cAdditivityRegressionTest` is a separate, untouched file and is run
 * alongside this one, not duplicated here).
 */
class Sprint10bInvariantTest extends TestCase
{
    use RefreshDatabase;

    private const CHUNK_MAIN = 9301;

    private const CHUNK_DECOMPOSED = 9302;

    private Convenio $convenio;

    private Employee $employee;

    private Document $proseDoc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(DocumentTypeSeeder::class);
        $this->configureAnswerModel();
        $this->buildWorld();
    }

    private function configureAnswerModel(): void
    {
        AnswerModelSetting::query()->delete();
        $s = new AnswerModelSetting(['provider' => 'claude']);
        $s->id = 1;
        $s->setKey('test-key-1234');
    }

    private function buildWorld(): void
    {
        $territory = Territory::create(['code' => '31', 'name' => 'Navarra', 'level' => 'provincial', 'aliases' => []]);
        $sector = Sector::create(['name' => 'Hostelería', 'aliases' => []]);

        $this->convenio = Convenio::create([
            'numero' => '31TEST0010B',
            'name' => 'Convenio Sprint 10b',
            'territory_id' => $territory->id,
            'sector_id' => $sector->id,
        ]);
        $this->employee = Employee::create([
            'email' => 'test-sprint10b@example.com',
            'full_name' => 'Worker Sprint 10b',
            'convenio_id' => $this->convenio->id,
            'territory_id' => $territory->id,
            'employment_type' => 'full_time',
            'status' => 'active',
        ]);

        $proseTypeId = (int) DocumentType::where('code', 'convenio_text')->value('id');
        $this->proseDoc = Document::create([
            'title' => 'Convenio Sprint 10b',
            'storage_path' => 'fake/'.uniqid(),
            'convenio_id' => $this->convenio->id,
            'document_type_id' => $proseTypeId,
            'authority_level' => 'official_convenio',
            'retrieval_status' => 'active',
            'language' => 'es',
            'tagging_status' => 'verified',
        ]);

        DB::table('document_chunks')->insert([
            'id' => self::CHUNK_MAIN, 'document_id' => $this->proseDoc->id, 'chunk_index' => 0,
            'page_from' => 12, 'page_to' => 12,
            'content' => 'Las vacaciones se disfrutan según lo pactado con la empresa.',
            'token_count' => 10, 'convenio_id' => $this->convenio->id, 'retrieval_status' => 'active',
            'authority_level' => 'official_convenio', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('document_chunks')->insert([
            'id' => self::CHUNK_DECOMPOSED, 'document_id' => $this->proseDoc->id, 'chunk_index' => 1,
            'page_from' => 13, 'page_to' => 13,
            'content' => 'Régimen de disfrute y fijación del periodo de vacaciones anual.',
            'token_count' => 10, 'convenio_id' => $this->convenio->id, 'retrieval_status' => 'active',
            'authority_level' => 'official_convenio', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // -- explicit_request (§C.6, D2/D3) --------------------------------------

    /** @return array<string,array{0:string}> */
    public static function explicitRequestPositives(): array
    {
        return [
            'card 9 verbatim' => ['Quiero hablar con una persona de Recursos Humanos, por favor.'],
            'bare "una persona"' => ['quiero hablar con una persona'],
            'RRHH-qualified "alguien"' => ['necesito hablar con alguien de RRHH'],
            'direct "recursos humanos"' => ['necesito hablar con recursos humanos'],
            'me gustaría + RR.HH.' => ['me gustaría contactar con RR.HH.'],
        ];
    }

    #[DataProvider('explicitRequestPositives')]
    public function test_explicit_request_fires_on_the_closed_positive_list(string $question): void
    {
        $fake = $this->bindFakeAi();
        $result = app(ChatService::class)->handleMessage($this->employee, $question);

        $this->assertSame('escalate', $result['outcome']);
        $this->assertSame('explicit_request', $result['trace']['floor_decision']['escalation_reason']);
        $this->assertSame(
            ChatService::EMPLOYEE_ESCALATION_MESSAGE,
            $result['answer'],
            'the one fixed neutral escalation message (ADR-0029) — never a reason-specific string'
        );
        $this->assertSame(
            0,
            $fake->routeCallCount,
            'a deterministic pre-check match must short-circuit before the LLM router is ever called'
        );
    }

    /** @return array<string,array{0:string}> */
    public static function explicitRequestNegatives(): array
    {
        return [
            'interrogative "how do I reach someone"' => ['¿con quién hablo para pedir mis vacaciones?'],
            'a person, not RRHH' => ['necesito hablar con mi jefe sobre las vacaciones'],
            // Build authorization D2's own correction case: the negative lookahead
            // must reject a non-RRHH "de <team>" qualifier, not just an unqualified
            // "de mi jefe" — this is the near-miss the review specifically named.
            'D2: alguien de mi equipo' => ['necesito hablar con alguien de mi equipo sobre el horario'],
            'canonical vacation question' => ['¿Cuántos días de vacaciones tengo?'],
        ];
    }

    #[DataProvider('explicitRequestNegatives')]
    public function test_explicit_request_does_not_fire_on_near_miss_negatives(string $question): void
    {
        $this->bindFakeAi();
        $result = app(ChatService::class)->handleMessage($this->employee, $question);

        $reason = $result['trace']['floor_decision']['escalation_reason'] ?? null;
        $this->assertNotSame('explicit_request', $reason, "false positive on: {$question}");
    }

    public function test_a_sensitive_and_human_request_message_escalates_sensitive_topic_not_explicit_request(): void
    {
        $this->bindFakeAi();
        // "finiquito" is a GuardrailService::SENSITIVE_PATTERNS match (disciplinary
        // bucket) — the guardrail baseline runs BEFORE this pre-check (Step 2,
        // before Step 2c) and must win (D3's precedence argument).
        $result = app(ChatService::class)->handleMessage(
            $this->employee,
            'Quiero hablar con una persona de Recursos Humanos sobre mi finiquito.'
        );

        $this->assertSame('escalate', $result['outcome']);
        $this->assertSame('sensitive_topic', $result['trace']['guardrail_check']['reason']);
        $this->assertSame('sensitive_topic', $result['trace']['floor_decision']['escalation_reason']);
    }

    public function test_explicit_request_is_covered_by_the_7g_matrix_and_registry_with_zero_changes(): void
    {
        $this->assertContains('explicit_request.explicit_request', EscalationExplainer::MATRIX);
        $this->assertTrue(EscalationExplainer::registryHasEntry('explicit_request.explicit_request'));

        $explanation = EscalationExplainer::explain('explicit_request', ['router_decision' => null]);
        $this->assertNotEmpty($explanation['fix_action'] ?? null);
    }

    // -- colloquial salary lexicon (§C.1, D1) --------------------------------

    /** @return array<string,array{0:string}> */
    public static function realSalaryQuestions(): array
    {
        // Every distinct real salary-routed question found on staging (plan §C.1
        // regression set) — the additive lexicon entry below must not change how
        // any of these route.
        return [
            'salario base mensual/anual' => ['¿Cuál es mi salario base mensual y mi salario anual según las tablas salariales vigentes?'],
            'cuánto cobro de salario base' => ['¿Cuánto cobro de salario base?'],
            'cuánto cobro este mes' => ['¿Cuánto cobro este mes según mi tabla salarial?'],
            'cuánto gano' => ['¿Cuánto gano?'],
            'cuánto gana mi categoría' => ['¿cuánto gana mi categoría este año?'],
        ];
    }

    #[DataProvider('realSalaryQuestions')]
    public function test_every_real_salary_question_still_routes_deterministically_to_salary(string $question): void
    {
        // Router faked to return off_domain — if the deterministic pre-classifier
        // did NOT match, the turn would escalate off_domain, not salary. Passing
        // means the match happened before the LLM was ever consulted.
        $fake = $this->bindFakeAi(routeLabel: 'off_domain');
        $result = app(ChatService::class)->handleMessage($this->employee, $question);

        $this->assertStringStartsWith(
            'deterministic_salary',
            $result['trace']['router_decision']['source'],
            "regression: \"{$question}\" no longer matches the deterministic salary pre-classifier"
        );
        $this->assertSame(0, $fake->routeCallCount);
    }

    public function test_the_new_colloquial_salary_pattern_matches_deterministically(): void
    {
        $fake = $this->bindFakeAi(routeLabel: 'off_domain');
        $result = app(ChatService::class)->handleMessage($this->employee, '¿Cuánto es el plus de transporte este mes?');

        $this->assertStringStartsWith('deterministic_salary', $result['trace']['router_decision']['source']);
        $this->assertSame(0, $fake->routeCallCount);
    }

    // -- decomposed_queries (§B, D4) ------------------------------------------

    public function test_decomposed_queries_is_a_separate_field_and_empty_by_default(): void
    {
        // hr-ai response omits the key entirely (pre-10b shape, or a provider
        // that hasn't been upgraded) — RouterService must default to [], never
        // error, per the additivity argument in plan.md §B.1.
        $this->bindFakeAi(decomposedQueries: null, omitDecomposedQueriesKey: true);
        $result = app(ChatService::class)->handleMessage($this->employee, 'pregunta canónica de prueba');

        $this->assertSame([], $result['trace']['router_decision']['decomposed_queries']);
    }

    public function test_decomposed_queries_join_the_retrieval_union_additively_on_the_chunk_id_set(): void
    {
        // Canonical-only run: no decomposition, only the main chunk is retrievable.
        $this->bindFakeAi(decomposedQueries: []);
        $canonical = app(ChatService::class)->handleMessage($this->employee, 'situational question');
        $canonicalIds = collect($canonical['trace']['retrieval']['chunks'])->pluck('chunk_id')->all();

        // Same question, hr-ai now proposes a decomposed rephrasing that happens to
        // surface a SECOND chunk the canonical query alone never retrieves.
        $this->bindFakeAi(decomposedQueries: ['régimen de disfrute y fijación del periodo de vacaciones']);
        $decomposed = app(ChatService::class)->handleMessage($this->employee, 'situational question');
        $decomposedIds = collect($decomposed['trace']['retrieval']['chunks'])->pluck('chunk_id')->all();

        // T-superset (build authorization): assert on the CHUNK-ID SET, not the
        // answer text — two runs can coincidentally produce the same prose while
        // differing in the evidence actually used.
        $this->assertContains(self::CHUNK_MAIN, $canonicalIds);
        $this->assertNotContains(self::CHUNK_DECOMPOSED, $canonicalIds, 'fixture premise: the canonical query alone must not surface the decomposed-only chunk');
        $this->assertContains(self::CHUNK_MAIN, $decomposedIds);
        $this->assertContains(self::CHUNK_DECOMPOSED, $decomposedIds, 'the decomposed query must add its chunk to the union, never lose the main one');

        // The synthesis cap grew symmetrically with subqueries (plan.md §B.2 fix):
        // 10 (base) + 2 * (0 subqueries + 1 decomposed query) = 12.
        $this->assertSame(12, $decomposed['trace']['retrieval']['rerank']['synthesis_cap']);
        // A canonical turn with no decomposition is UNCHANGED at the base cap.
        $this->assertSame(10, $canonical['trace']['retrieval']['rerank']['synthesis_cap']);
    }

    public function test_decomposed_queries_cannot_widen_scope_or_authority_the_deterministic_guard(): void
    {
        $fake = $this->bindFakeAi(decomposedQueries: ['régimen de disfrute y fijación del periodo de vacaciones']);
        app(ChatService::class)->handleMessage($this->employee, 'situational question');

        // Two SCOPED passes (main question + the one decomposed query) plus one
        // fixed national-law-only pass = 3 calls total (retrieveUnion() issues
        // the scoped passes first, the national-law pass last — ChatService.php).
        $this->assertCount(3, $fake->retrieveCalls);

        $scopedCalls = array_slice($fake->retrieveCalls, 0, 2);
        $first = $scopedCalls[0];
        foreach ($scopedCalls as $call) {
            // Only `query` may vary between the SCOPED passes. convenio_id/
            // include_national_law/retrieval_status/as_of_date/k are fixed from
            // the turn's own resolved scope for every one of them — a decomposed
            // query's TEXT is the only thing that ever reaches /retrieve; it
            // cannot smuggle scope or authority (plan.md §B.3).
            foreach (['convenio_id', 'include_national_law', 'retrieval_status', 'as_of_date', 'k'] as $key) {
                $this->assertSame(
                    $first[$key],
                    $call[$key],
                    "pass for query \"{$call['query']}\" varied {$key} among the SCOPED passes — the deterministic guard is broken"
                );
            }
        }

        // The national-law-only pass is a KNOWN, distinct, deliberately-unscoped
        // pass type (convenio_id forced null by design, unrelated to this guard).
        // Confirm it always uses the ORIGINAL question, never a decomposed
        // query's rephrased text.
        $nationalLawCall = $fake->retrieveCalls[2];
        $this->assertSame('situational question', $nationalLawCall['query']);
        $this->assertNull($nationalLawCall['convenio_id']);
    }

    // -- helpers ---------------------------------------------------------------

    /**
     * Bind a fake ExtractionClient. Returns it so tests can inspect call counts
     * and captured /retrieve params.
     */
    private function bindFakeAi(
        string $routeLabel = 'prose',
        ?array $decomposedQueries = [],
        bool $omitDecomposedQueriesKey = false,
    ): object {
        $mainChunkId = self::CHUNK_MAIN;
        $decomposedChunkId = self::CHUNK_DECOMPOSED;
        $docId = $this->proseDoc->id;

        $fake = new class($routeLabel, $decomposedQueries, $omitDecomposedQueriesKey, $mainChunkId, $decomposedChunkId, $docId) extends ExtractionClient
        {
            public int $routeCallCount = 0;

            /** @var list<array<string,mixed>> */
            public array $retrieveCalls = [];

            public function __construct(
                private string $routeLabel,
                private ?array $decomposedQueries,
                private bool $omitDecomposedQueriesKey,
                private int $mainChunkId,
                private int $decomposedChunkId,
                private int $docId,
            ) {}

            public function route(string $question, string $decryptedKey, array $providerConfig): array
            {
                $this->routeCallCount++;
                $resp = [
                    'label' => $this->routeLabel,
                    'confidence' => 0.95,
                    'subqueries' => [],
                    'reason' => 'llm',
                    'trace_fragment' => [],
                ];
                if (! $this->omitDecomposedQueriesKey) {
                    $resp['decomposed_queries'] = $this->decomposedQueries ?? [];
                }

                return $resp;
            }

            public function retrieve(array $params): array
            {
                $this->retrieveCalls[] = $params;

                $chunks = [];
                if (str_contains($params['query'], 'situational') || str_contains($params['query'], 'canónica')) {
                    $chunks[] = $this->chunk($this->mainChunkId, 0.90, 'Las vacaciones se disfrutan según lo pactado con la empresa.');
                }
                if (str_contains($params['query'], 'régimen de disfrute')) {
                    $chunks[] = $this->chunk($this->decomposedChunkId, 0.88, 'Régimen de disfrute y fijación del periodo de vacaciones anual.');
                }
                // Generic fallback so any other test question (explicit_request
                // negatives, salary regression questions that fall through, etc.)
                // still clears the retrieval floor deterministically.
                if ($chunks === []) {
                    $chunks[] = $this->chunk($this->mainChunkId, 0.90, 'Las vacaciones se disfrutan según lo pactado con la empresa.');
                }

                return ['eligible_total' => count($chunks), 'chunks' => $chunks];
            }

            private function chunk(int $id, float $score, string $content): array
            {
                return [
                    'id' => $id, 'document_id' => $this->docId,
                    'page_from' => 1, 'page_to' => 1, 'score' => $score,
                    'authority_level' => 'official_convenio', 'content' => $content,
                ];
            }

            public function synthesise(string $question, array $chunks, string $decryptedKey, array $providerConfig): array
            {
                $first = $chunks[0] ?? [];

                return [
                    'answer' => 'Respuesta de prueba. [Fuente 1]',
                    'citations' => [[
                        'chunk_id' => $first['chunk_id'] ?? null,
                        'document_id' => $first['document_id'] ?? null,
                        'page_from' => $first['page_from'] ?? 1,
                        'page_to' => $first['page_to'] ?? 1,
                        'authority_level' => $first['authority_level'] ?? 'official_convenio',
                    ]],
                    'grounding_signal' => ['grounded' => true, 'citation_count' => 1, 'top_chunk_score' => 0.90],
                    'confidence' => 0.90,
                    'authority_used' => [$first['authority_level'] ?? 'official_convenio'],
                    'trace_fragment' => [],
                ];
            }

            public function ground(string $question, string $answer, array $chunks, string $decryptedKey, array $providerConfig): array
            {
                return ['grounded' => true, 'claims' => [], 'ungrounded' => [], 'error' => null, 'trace_fragment' => []];
            }
        };

        $this->app->instance(ExtractionClient::class, $fake);

        return $fake;
    }
}
