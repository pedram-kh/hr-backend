<?php

namespace Tests\Feature;

use App\Models\AnswerModelSetting;
use App\Models\Convenio;
use App\Models\Document;
use App\Models\Employee;
use App\Models\MessageTrace;
use App\Models\ReferenceFact;
use App\Models\Sector;
use App\Models\Territory;
use App\Models\Topic;
use App\Services\ChatService;
use App\Services\ExtractionClient;
use Database\Seeders\DocumentTypeSeeder;
use Database\Seeders\RoleSeeder;
use Tests\TestCase;

/**
 * Sprint 7c Phase 2 acceptance proof (ADR-0023) — the fact + convenio-prose MERGE.
 *
 * The line Phase 2 draws (tested here): Phase 1 quotes a verified value and SKIPS
 * /ground; Phase 2 GENERATES one composed answer and therefore MUST /ground. The
 * fact enters /synthesise + /ground as ONE MORE typed, authority-labelled source
 * (source_type=reference_fact, chunk_id=null, structured_reference), ordered BELOW
 * the convenio by the existing precedence rule — they are reused, never rewritten.
 *
 * The safety spine that Phase 2 must not weaken:
 *   - the convenio always GOVERNS; the fact is bounded at structured_reference and
 *     can never outrank it;
 *   - a genuine fact-vs-convenio SAME-POINT conflict ESCALATES — never blends,
 *     never silently prefers the structured fact;
 *   - a composed answer that fails the per-claim entailment gate ESCALATES.
 */
class Sprint7cCompositionTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    private Convenio $convenio;

    private Topic $topic;

    private Document $factDoc;

    private Document $proseDoc;

    private Territory $territory;

    private Sector $sector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(DocumentTypeSeeder::class);

        $this->territory = Territory::create(['code' => '31', 'name' => 'Navarra', 'level' => 'provincial', 'aliases' => []]);
        $this->sector = Sector::create(['name' => 'Hostelería', 'aliases' => []]);
        $this->convenio = Convenio::create([
            'numero' => '31000505', 'name' => 'Hostelería de Navarra',
            'territory_id' => $this->territory->id, 'sector_id' => $this->sector->id,
        ]);
        $this->topic = Topic::firstOrCreate(['name' => 'periodo de prueba'], ['status' => 'approved']);

        $docTypeId = \App\Models\DocumentType::query()->value('id');
        $this->factDoc = Document::create([
            'title' => 'Periodos de prueba (referencia)', 'storage_path' => 'fake/ref.docx',
            'convenio_id' => $this->convenio->id, 'document_type_id' => $docTypeId,
            'authority_level' => 'official_convenio', 'retrieval_status' => 'active', 'language' => 'es',
            'tagging_status' => 'verified',
        ]);
        $this->proseDoc = Document::create([
            'title' => 'Convenio Hostelería Navarra (texto)', 'storage_path' => 'fake/convenio.pdf',
            'convenio_id' => $this->convenio->id, 'document_type_id' => $docTypeId,
            'authority_level' => 'official_convenio', 'retrieval_status' => 'active', 'language' => 'es',
            'tagging_status' => 'verified',
        ]);
    }

    // ---- Happy path: ONE grounded composed answer, the convenio governing ------

    public function test_composition_merges_fact_and_convenio_into_one_grounded_answer_that_must_ground(): void
    {
        $employee = $this->employee();
        $this->verifiedFact('periodo de prueba 90 días');

        // Governing convenio prose on the SAME topic, AGREEING (no conflict).
        $this->bindComposingAi(
            proseContent: 'El periodo de prueba para el personal será de 90 días naturales.',
            synth: [
                'answer' => 'Tu periodo de prueba es de 90 días [Fuente 1][Fuente 2].',
                'confidence' => 0.92,
                'authority_used' => ['official_convenio', 'structured_reference'],
                'citations' => [
                    ['chunk_id' => 7001, 'source_type' => 'chunk', 'document_id' => $this->proseDoc->id, 'page_from' => 3, 'page_to' => 3, 'authority_level' => 'official_convenio'],
                    ['chunk_id' => null, 'source_type' => 'reference_fact', 'document_id' => $this->factDoc->id, 'authority_level' => 'structured_reference'],
                ],
            ],
            ground: ['grounded' => true, 'claims' => [['claim' => 'periodo de prueba 90 días', 'grounded' => true, 'supporting_source' => 1]], 'ungrounded' => []],
        );

        $result = app(ChatService::class)->handleMessage($employee, '¿cuál es mi periodo de prueba?');

        $this->assertSame('answer', $result['outcome']);
        $this->assertStringContainsString('90 días', $result['answer']);

        // Multi-source citation set: the fact (chunk_id=null, structured_reference)
        // AND the governing convenio chunk.
        $this->assertCount(2, $result['citations']);
        $fact = collect($result['citations'])->firstWhere('is_reference_fact', true);
        $this->assertNotNull($fact, 'the fact must be cited as a structured_reference source');
        $this->assertNull($fact['chunk_id']);
        $this->assertSame('structured_reference', $fact['authority_level']);
        $convenio = collect($result['citations'])->firstWhere('chunk_id', 7001);
        $this->assertNotNull($convenio, 'the governing convenio chunk must be cited');
        $this->assertSame('official_convenio', $convenio['authority_level']);

        $trace = MessageTrace::firstOrFail()->trace;
        $this->assertSame('reference_fact_composition', $trace['floor_decision']['path']);
        $this->assertSame('answer', $trace['floor_decision']['outcome']);
        $this->assertTrue($trace['composition']['detected']);
        $this->assertGreaterThanOrEqual(1, $trace['composition']['governing_on_topic_chunks']);

        // MUST-GROUND (P2): a composed answer is GENERATED → the per-claim gate ran.
        $this->assertTrue($trace['floor_decision']['grounding']['checked']);
        $this->assertTrue($trace['floor_decision']['grounding']['grounded']);
        $this->assertContains('official_convenio', $trace['floor_decision']['authority_used']);
        $this->assertContains('structured_reference', $trace['floor_decision']['authority_used']);
    }

    // ---- Same-point conflict → escalate, NEVER blends (convenio governs) -------

    public function test_same_point_conflict_escalates_and_never_blends(): void
    {
        $employee = $this->employee();
        $this->verifiedFact('periodo de prueba 90 días');

        // Governing convenio prose DISAGREES on the same unit (60 vs 90 días). The
        // conflict is detected deterministically BEFORE /synthesise — so synthesise
        // EXPLODES if reached, proving the turn never blends the two figures.
        $this->bindComposingAi(
            proseContent: 'El periodo de prueba será de 60 días.',
            synth: null, // exploding — must not be reached
            ground: null,
        );

        $result = app(ChatService::class)->handleMessage($employee, '¿cuál es mi periodo de prueba?');

        $this->assertSame('escalate', $result['outcome']);
        $this->assertSame(ChatService::COMPOSITION_CONFLICT_MESSAGE, $result['answer']);
        $this->assertSame([], $result['citations']);

        $trace = MessageTrace::firstOrFail()->trace;
        $this->assertSame('reference_fact_composition', $trace['floor_decision']['path']);
        $this->assertSame('conflict', $trace['floor_decision']['escalation_reason']);
        $this->assertTrue($trace['composition']['conflict']['conflict']);
        $this->assertSame('dia', $trace['composition']['conflict']['unit']);
        // The grounding gate is never even reached — there is nothing to entail.
        $this->assertArrayNotHasKey('grounding', $trace['floor_decision']);
    }

    // ---- A composed answer that fails the entailment gate ESCALATES -----------

    public function test_composed_answer_failing_grounding_escalates_low_confidence(): void
    {
        $employee = $this->employee();
        $this->verifiedFact('periodo de prueba 90 días');

        $this->bindComposingAi(
            proseContent: 'El periodo de prueba para el personal será de 90 días naturales.',
            synth: [
                'answer' => 'Tu periodo de prueba es de 90 días y además tienes 5 días extra [Fuente 1].',
                'confidence' => 0.9,
                'authority_used' => ['official_convenio', 'structured_reference'],
                'citations' => [
                    ['chunk_id' => 7001, 'source_type' => 'chunk', 'document_id' => $this->proseDoc->id, 'page_from' => 3, 'page_to' => 3, 'authority_level' => 'official_convenio'],
                ],
            ],
            // The entailment gate rejects the fabricated "5 días extra" claim.
            ground: ['grounded' => false, 'claims' => [['claim' => '5 días extra', 'grounded' => false, 'supporting_source' => null]], 'ungrounded' => ['5 días extra']],
        );

        $result = app(ChatService::class)->handleMessage($employee, '¿cuál es mi periodo de prueba?');

        $this->assertSame('escalate', $result['outcome']);
        $this->assertSame(ChatService::ESCALATION_MESSAGE, $result['answer']);

        $trace = MessageTrace::firstOrFail()->trace;
        $this->assertSame('reference_fact_composition', $trace['floor_decision']['path']);
        $this->assertSame('low_confidence', $trace['floor_decision']['escalation_reason']);
        $this->assertTrue($trace['floor_decision']['grounding']['checked']);
        $this->assertFalse($trace['floor_decision']['grounding']['grounded']);
    }

    // ---- helpers ------------------------------------------------------------

    private function employee(): Employee
    {
        return Employee::create([
            'email' => 'emp'.uniqid().'@example.com', 'full_name' => 'Empleada',
            'convenio_id' => $this->convenio->id, 'job_category_id' => null,
            'territory_id' => $this->territory->id, 'employment_type' => 'full_time', 'status' => 'active',
        ]);
    }

    private function verifiedFact(string $value): ReferenceFact
    {
        return ReferenceFact::create([
            'convenio_id' => $this->convenio->id,
            'topic_id' => $this->topic->id,
            'job_category_id' => null,
            'group_label' => null,
            'value' => $value,
            'authority_level' => 'structured_reference',
            'source' => 'admin_manual',
            'status' => 'verified',
            'validity_start' => null,
            'validity_end' => null,
            'source_document_id' => $this->factDoc->id,
            'source_locator' => 'p.3 §2',
        ]);
    }

    /**
     * Bind a fake AI for composition: /retrieve returns one governing convenio
     * chunk (id 7001) on the topic; /synthesise + /ground return the supplied
     * envelopes (null = explode if reached, to prove a path is never taken).
     *
     * @param  array<string,mixed>|null  $synth
     * @param  array<string,mixed>|null  $ground
     */
    private function bindComposingAi(string $proseContent, ?array $synth, ?array $ground): void
    {
        $chunk = [
            'id' => 7001,
            'document_id' => $this->proseDoc->id,
            'page_from' => 3,
            'page_to' => 3,
            'content' => $proseContent,
            'score' => 0.91,
            'authority_level' => 'official_convenio',
        ];

        $fake = new class($chunk, $synth, $ground) extends ExtractionClient
        {
            /** @param array<string,mixed> $chunk */
            public function __construct(private array $chunk, private ?array $synth, private ?array $ground)
            {
            }

            public function retrieve(array $params): array
            {
                // Governing convenio prose only on the convenio-scoped pass (the
                // national-law-only pass returns nothing).
                if (($params['convenio_id'] ?? null) === null) {
                    return ['chunks' => [], 'eligible_total' => 0];
                }

                return ['chunks' => [$this->chunk], 'eligible_total' => 1];
            }

            public function synthesise(string $q, array $ch, string $k, array $c): array
            {
                if ($this->synth === null) {
                    throw new \RuntimeException('a same-point conflict must escalate BEFORE /synthesise — never blend');
                }

                return $this->synth;
            }

            public function ground(string $q, string $a, array $ch, string $k, array $c): array
            {
                if ($this->ground === null) {
                    throw new \RuntimeException('/ground must not be reached on this path');
                }

                return $this->ground + ['trace_fragment' => []];
            }
        };

        $this->app->instance(ExtractionClient::class, $fake);

        // The cited convenio chunk must exist (message_citations.chunk_id FK).
        \Illuminate\Support\Facades\DB::table('document_chunks')->insert([
            'id' => 7001, 'document_id' => $this->proseDoc->id, 'chunk_index' => 0,
            'page_from' => 3, 'page_to' => 3, 'content' => $proseContent, 'token_count' => 12,
            'convenio_id' => $this->convenio->id, 'retrieval_status' => 'active',
            'authority_level' => 'official_convenio', 'created_at' => now(), 'updated_at' => now(),
        ]);

        // The composed answer needs the answer model configured (P2 generates and
        // grounds). Force the row at id=1 (sequences aren't rolled back between test
        // classes; current() looks up id=1).
        AnswerModelSetting::query()->delete();
        $s = new AnswerModelSetting(['provider' => 'claude']);
        $s->id = 1;
        $s->setKey('test-key-1234');
    }
}
