<?php

namespace Tests\Feature;

use App\Models\AnswerModelSetting;
use App\Models\Convenio;
use App\Models\Document;
use App\Models\DocumentPage;
use App\Models\DocumentType;
use App\Models\ReferenceFact;
use App\Models\Sector;
use App\Models\Territory;
use App\Models\Topic;
use App\Services\ExtractionClient;
use App\Services\ReferenceFactProposalService;
use Database\Seeders\DocumentTypeSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sprint 10c, Step 3 (plan §A.1/§D.11) — the new per-(document, topic) driver,
 * alongside (not replacing) the 7b-2 `reference_source` path. Stubbed
 * `ExtractionClient` throughout (no hr-ai, no LLM, no network) — this proves
 * the DRIVER'S contract (source selection, passage-scoping, single-convenio
 * candidate payload, target_topic wiring, persist reuse), not prompt quality
 * (that is the gold-fixture eval, §D.10).
 *
 * Covers:
 *  - D6 source-selection invariant: a convenio with only `historical` text
 *    yields ZERO eligible documents/candidate pages — proven directly against
 *    `eligibleDocumentsForTopic()`, not just inferred from the WHERE clause.
 *  - Passage-scoping: only topic-anchored pages are sent, not the whole doc.
 *  - `proposeForTopic()` calls hr-ai with `target_topic` set and a
 *    SINGLE-convenio candidate list, then persists via the SAME `persist()`
 *    path `propose()` uses (D4's backstop, idempotent upsert, etc. all apply
 *    unchanged — re-proven only where the wiring itself could regress).
 */
class Sprint10cTopicSegmentationTest extends TestCase
{
    use RefreshDatabase;

    private Territory $navarra;

    private Sector $hosteleria;

    private Convenio $convenio;

    private Topic $permisos;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(DocumentTypeSeeder::class);

        $this->navarra = Territory::create(['code' => '31', 'name' => 'Navarra', 'level' => 'provincial', 'aliases' => []]);
        $this->hosteleria = Sector::create(['name' => 'Hostelería', 'aliases' => []]);
        $this->convenio = Convenio::create([
            'numero' => '31000000', 'name' => 'Hostelería Navarra',
            'territory_id' => $this->navarra->id, 'sector_id' => $this->hosteleria->id,
        ]);
        $this->permisos = Topic::firstOrCreate(['name' => 'permisos'], ['status' => 'approved']);

        $setting = new AnswerModelSetting(['provider' => 'claude']);
        $setting->id = 1;
        $setting->save();
        $setting->setKey('sk-test-not-used');
        config(['services.hr_ai.answer_model' => 'claude-test']);
    }

    // ---- D6 — source selection is active-only, full stop ---------------------

    public function test_d6_convenio_with_only_historical_text_yields_zero_eligible_documents(): void
    {
        $this->convenioTextDocument(retrievalStatus: 'historical', pages: [
            'Artículo 20. Permisos retribuidos: 16 días naturales por matrimonio.',
        ]);

        $service = $this->serviceReturning([]);
        $eligible = $service->eligibleDocumentsForTopic($this->permisos);

        $this->assertCount(0, $eligible, 'a historical-only convenio must contribute zero candidate pages (D6)');
    }

    public function test_d6_active_convenio_text_with_an_anchored_page_is_eligible(): void
    {
        $this->convenioTextDocument(retrievalStatus: 'active', pages: [
            'Artículo 20. Permisos retribuidos: 16 días naturales por matrimonio.',
        ]);

        $service = $this->serviceReturning([]);
        $eligible = $service->eligibleDocumentsForTopic($this->permisos);

        $this->assertCount(1, $eligible);
    }

    public function test_d6_active_document_of_a_different_type_is_not_eligible(): void
    {
        // e.g. a reference_source upload — active, but the wrong document_type
        // for this driver (that source stays on the OTHER path, propose()).
        $type = DocumentType::where('code', 'reference_source')->firstOrFail();
        $doc = Document::create([
            'title' => 'PERIODOS DE PRUEBA', 'source_filename' => 'x.docx', 'storage_path' => 'documents/test/x.docx',
            'content_hash' => bin2hex(random_bytes(16)), 'document_type_id' => $type->id,
            'convenio_id' => $this->convenio->id, 'retrieval_status' => 'active', 'language' => 'es',
            'authority_level' => 'official_convenio', 'tagging_status' => 'under_review',
        ]);
        DocumentPage::create(['document_id' => $doc->id, 'page_number' => 1, 'text' => 'Permisos retribuidos: 16 días naturales por matrimonio.']);

        $eligible = $this->serviceReturning([])->eligibleDocumentsForTopic($this->permisos);
        $this->assertCount(0, $eligible, 'only document_type=convenio_text is eligible for this driver');
    }

    public function test_d6_active_convenio_text_with_no_anchored_page_is_not_eligible(): void
    {
        $this->convenioTextDocument(retrievalStatus: 'active', pages: [
            'Artículo 30. Movilidad geográfica y traslados del personal.',
        ]);

        $eligible = $this->serviceReturning([])->eligibleDocumentsForTopic($this->permisos);
        $this->assertCount(0, $eligible, 'a page with no anchor for this topic must not make the document eligible');
    }

    // ---- Passage-scoping: only anchored pages are sent ------------------------

    public function test_only_anchored_pages_are_sent_not_the_whole_document(): void
    {
        $doc = $this->convenioTextDocument(retrievalStatus: 'active', pages: [
            'Artículo 15. Movilidad geográfica: normas de traslado del personal.', // p.1, unrelated
            'Artículo 20. Permisos retribuidos: 16 días naturales por matrimonio.', // p.2, anchored
        ]);

        $spy = $this->spyingService();
        $spy['service']->proposeForTopic($doc, $this->permisos, '2024-01-01', '2027-12-31');

        $sentText = $spy['ai']->calls[0]['pages_text'];
        $this->assertStringNotContainsString('Movilidad geográfica', $sentText, 'an unanchored page must not be sent');
        $this->assertStringContainsString('Permisos retribuidos', $sentText);
        $this->assertStringContainsString('[loc:p2]', $sentText, 'the anchored page keeps a real, checkable locator');
    }

    public function test_calls_hr_ai_with_target_topic_and_single_convenio_candidate_list(): void
    {
        $doc = $this->convenioTextDocument(retrievalStatus: 'active', pages: [
            'Artículo 20. Permisos retribuidos: 16 días naturales por matrimonio.',
        ]);

        $spy = $this->spyingService();
        $spy['service']->proposeForTopic($doc, $this->permisos, '2024-01-01', '2027-12-31');

        $call = $spy['ai']->calls[0];
        $this->assertSame(['id' => $this->permisos->id, 'name' => 'permisos'], $call['target_topic']);
        $this->assertSame([], $call['candidate_topics'], 'candidate_topics is unused/empty when target_topic is set');
        $this->assertCount(1, $call['candidate_convenios'], 'single-convenio: no cross-province candidate list needed');
        $this->assertSame($this->convenio->id, $call['candidate_convenios'][0]['id']);
    }

    // ---- persist() reuse: the shared path still applies (inert, upsert, D4) --

    public function test_propose_for_topic_persists_via_the_shared_inert_path(): void
    {
        $doc = $this->convenioTextDocument(retrievalStatus: 'active', pages: [
            'Artículo 20. Permisos retribuidos: 16 días naturales por matrimonio.',
        ]);

        $service = $this->serviceReturning([
            [
                'convenio_id' => $this->convenio->id,
                'job_category_id' => null,
                'group_label' => null,
                'topic_id' => $this->permisos->id,
                'value' => '16 días naturales por matrimonio',
                'raw_values' => null,
                'confidence' => 0.92,
                'uncertainty' => null,
                'source_locator' => 'p.1',
                'source_excerpt' => 'Artículo 20. Permisos retribuidos: 16 días naturales por matrimonio.',
            ],
        ]);

        $summary = $service->proposeForTopic($doc, $this->permisos, '2024-01-01', '2027-12-31');

        $this->assertSame('ok', $summary['status']);
        $fact = ReferenceFact::where('source', 'ai_agent')->firstOrFail();
        $this->assertSame('needs_review', $fact->status, 'inert until a human verifies (ADR-0020)');
        $this->assertSame('structured_reference', $fact->authority_level, 'the authority floor is forced here too');
        $this->assertSame('2024-01-01', $fact->validity_start?->toDateString(), 'stamps the CAPTURED window, exactly like propose()');
        $this->assertSame('2027-12-31', $fact->validity_end?->toDateString());
    }

    public function test_propose_for_topic_uses_captured_validity_not_documents_current_validity(): void
    {
        $doc = $this->convenioTextDocument(retrievalStatus: 'active', pages: [
            'Artículo 20. Permisos retribuidos: 16 días naturales por matrimonio.',
        ], validityStart: '2024-01-01', validityEnd: '2027-12-31');

        $capturedStart = $doc->validity_start?->toDateString();
        $capturedEnd = $doc->validity_end?->toDateString();

        // Simulate the same dispatch/execute race as propose()'s test.
        $doc->update(['validity_start' => '2030-01-01', 'validity_end' => '2031-12-31']);
        $doc->refresh();

        $service = $this->serviceReturning([
            [
                'convenio_id' => $this->convenio->id, 'job_category_id' => null, 'group_label' => null,
                'topic_id' => $this->permisos->id, 'value' => '16 días naturales por matrimonio',
                'raw_values' => null, 'confidence' => 0.9, 'uncertainty' => null,
                'source_locator' => 'p.1', 'source_excerpt' => 'x',
            ],
        ]);

        $service->proposeForTopic($doc, $this->permisos, $capturedStart, $capturedEnd);

        $fact = ReferenceFact::where('source', 'ai_agent')->firstOrFail();
        $this->assertSame('2024-01-01', $fact->validity_start?->toDateString(), 'must stamp the CAPTURED window, not the post-edit one');
        $this->assertSame('2027-12-31', $fact->validity_end?->toDateString());
    }

    public function test_propose_for_topic_skips_when_topic_has_no_lexicon_entry(): void
    {
        $doc = $this->convenioTextDocument(retrievalStatus: 'active', pages: [
            'Artículo 20. Permisos retribuidos: 16 días naturales por matrimonio.',
        ]);
        $unmapped = Topic::create(['name' => 'una cosa muy rara sin lexicon', 'status' => 'approved']);

        $summary = $this->serviceReturning([])->proposeForTopic($doc, $unmapped, '2024-01-01', '2027-12-31');

        $this->assertSame('skipped', $summary['status']);
        $this->assertSame('topic_not_in_lexicon', $summary['reason']);
        $this->assertSame(0, ReferenceFact::count());
    }

    // ---- helpers --------------------------------------------------------------

    private function convenioTextDocument(string $retrievalStatus, array $pages, ?string $validityStart = '2024-01-01', ?string $validityEnd = '2027-12-31'): Document
    {
        $type = DocumentType::where('code', 'convenio_text')->firstOrFail();
        $doc = Document::create([
            'title' => $this->convenio->name,
            'source_filename' => 'convenio.docx',
            'storage_path' => 'documents/test/convenio.docx',
            'content_hash' => bin2hex(random_bytes(16)),
            'document_type_id' => $type->id,
            'convenio_id' => $this->convenio->id,
            'validity_start' => $validityStart,
            'validity_end' => $validityEnd,
            'language' => 'es',
            'retrieval_status' => $retrievalStatus,
            'authority_level' => 'official_convenio',
            'tagging_status' => 'under_review',
        ]);
        foreach (array_values($pages) as $i => $text) {
            DocumentPage::create(['document_id' => $doc->id, 'page_number' => $i + 1, 'text' => $text]);
        }

        return $doc;
    }

    /**
     * The persist service wired with a STUBBED ExtractionClient that returns the
     * given facts envelope — no hr-ai call, no LLM, no network.
     *
     * @param  list<array<string,mixed>>  $facts
     */
    private function serviceReturning(array $facts): ReferenceFactProposalService
    {
        $fakeAi = new class($facts) extends ExtractionClient
        {
            /** @param list<array<string,mixed>> $facts */
            public function __construct(private array $facts) {}

            public function segmentFacts(
                int $documentId,
                string $documentUuid,
                string $sourceFormat,
                string $pagesText,
                array $candidateConvenios,
                array $candidateTopics,
                string $decryptedKey,
                array $providerConfig,
                ?array $targetTopic = null,
            ): array {
                return ['facts' => $this->facts, 'trace_fragment' => ['stub' => true]];
            }
        };

        return new ReferenceFactProposalService($fakeAi);
    }

    /**
     * A stubbed ExtractionClient that RECORDS every call's arguments (for
     * asserting what was actually sent to hr-ai), returning an empty facts
     * envelope. Read `$spy['ai']->calls` AFTER invoking `$spy['service']`.
     *
     * @return array{service:ReferenceFactProposalService, ai:ExtractionClient}
     */
    private function spyingService(): array
    {
        $fakeAi = new class extends ExtractionClient
        {
            /** @var list<array<string,mixed>> */
            public array $calls = [];

            public function segmentFacts(
                int $documentId,
                string $documentUuid,
                string $sourceFormat,
                string $pagesText,
                array $candidateConvenios,
                array $candidateTopics,
                string $decryptedKey,
                array $providerConfig,
                ?array $targetTopic = null,
            ): array {
                $this->calls[] = [
                    'document_id' => $documentId,
                    'pages_text' => $pagesText,
                    'candidate_convenios' => $candidateConvenios,
                    'candidate_topics' => $candidateTopics,
                    'target_topic' => $targetTopic,
                ];

                return ['facts' => [], 'trace_fragment' => ['stub' => true]];
            }
        };

        return ['service' => new ReferenceFactProposalService($fakeAi), 'ai' => $fakeAi];
    }
}
