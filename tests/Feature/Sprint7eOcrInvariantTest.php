<?php

namespace Tests\Feature;

use App\Jobs\OcrDocumentPages;
use App\Jobs\OcrPage;
use App\Models\AnswerModelSetting;
use App\Models\Convenio;
use App\Models\Document;
use App\Models\DocumentPage;
use App\Models\DocumentType;
use App\Models\Sector;
use App\Models\Territory;
use App\Services\DocumentIngestor;
use App\Services\ExtractionClient;
use App\Services\OcrService;
use App\Support\VocabularyResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Sprint 7e acceptance proof (ADR-0026, review.md §2.8). Proves:
 *
 *  (a) the NO-OP INVARIANT — a text-layer PDF ingests with
 *      `extraction_source = 'text_layer'` on every page and NO `OcrDocumentPages`/
 *      `OcrPage` job is ever dispatched, even when `--ocr` is opted in (the fallback
 *      only ever fires on a page whose native text was actually empty).
 *  (a', positive control) a text-less page, opted in, DOES dispatch exactly one
 *      `OcrDocumentPages` job (which itself fans out one `OcrPage` per pending page).
 *  (c) the UNDER_REVIEW / 0-CHUNKS GATE — an OCR'd page (written via
 *      `OcrService::ocrOnePage()` directly, bypassing the queue) leaves the
 *      document `under_review` and selects ZERO chunks under the real, unmodified
 *      `chunks:embed` gate query; after the human `confirm()`, the SAME document is
 *      selected and a real (faked) `/embed` call succeeds against it.
 *
 * (b) — the hr-ai-side sidecar invariant — is `hr-ai/scripts/ocr_sidecar_test.py`
 * (hr-ai has no pytest suite; that script is this repo's own analogue of this
 * proof, run and captured green separately, per review.md §2.8).
 */
class Sprint7eOcrInvariantTest extends TestCase
{
    use RefreshDatabase;

    private Territory $territory;

    private Sector $sector;

    private Convenio $convenio;

    private DocumentType $convenioType;

    private FakeOcrExtractionClient $fake;

    protected function setUp(): void
    {
        parent::setUp();

        $this->territory = Territory::create(['code' => '31', 'name' => 'Navarra', 'level' => 'provincial', 'aliases' => []]);
        $this->sector = Sector::create(['name' => 'Cultura', 'aliases' => []]);
        $this->convenio = Convenio::create([
            'numero' => '31000199', 'name' => 'Pacto Cultura Navarra',
            'territory_id' => $this->territory->id, 'sector_id' => $this->sector->id,
        ]);
        $this->convenioType = DocumentType::create(['code' => 'convenio_text', 'name' => 'Texto del convenio']);

        $this->fake = new FakeOcrExtractionClient;
        $this->app->instance(ExtractionClient::class, $this->fake);

        // An answer-model key must be configured — OcrService::ocrOnePage() skips
        // (leaves the page ocr_pending) without one, same posture as
        // TagProposalService. id = 1 pinned (Postgres sequences aren't rolled back
        // between tests) — the proven Sprint-6/7a pattern.
        $setting = new AnswerModelSetting(['provider' => 'claude']);
        $setting->id = 1;
        $setting->save();
        $setting->setKey('sk-test-key-1234', null);
    }

    private function tmpFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ocrtest');
        file_put_contents($path, $contents);

        return $path;
    }

    /** The `chunks:embed` selection, run for real via Artisan (not a hand-copied query). */
    private function embedSelectionCount(string $uuid): int
    {
        Artisan::call('chunks:embed', ['--document' => $uuid, '--dry-run' => true]);
        $output = Artisan::output();
        preg_match('/selected for embedding: (\d+)/', $output, $m);

        return (int) ($m[1] ?? -1);
    }

    // ---- (a) no-op invariant -------------------------------------------------

    public function test_no_op_invariant_text_layer_pdf_ingests_with_zero_ocr_calls(): void
    {
        Storage::fake('s3');
        Queue::fake();

        // Every page has real text — hr-ai's /extract would report
        // extraction_source = 'text_layer' for all of them regardless of the
        // ocr flag (a page is only ever a candidate when its native text is
        // empty). $ocr = true here deliberately, to prove the no-op holds even
        // when OCR is opted IN — the gate is "was this page's text empty," not
        // "was --ocr passed."
        $this->fake->extractResponse = [
            'page_count' => 2,
            'pages' => [
                ['page_number' => 1, 'text' => 'Artículo 1. Objeto del pacto.', 'image_key' => 'documents/x/pages/0001.jpg', 'extraction_source' => 'text_layer'],
                ['page_number' => 2, 'text' => 'Artículo 2. Ámbito de aplicación.', 'image_key' => 'documents/x/pages/0002.jpg', 'extraction_source' => 'text_layer'],
            ],
        ];

        $ingestor = new DocumentIngestor($this->fake);
        $result = $ingestor->ingest(
            $this->tmpFile('%PDF-1.4 fake bytes'),
            'pacto-cultura.pdf',
            'Navarra',
            'Navarra/pacto-cultura.pdf',
            null,
            new VocabularyResolver,
            false, // asReference
            true,  // ocr = true (opted in)
            60,
        );

        $document = Document::where('uuid', $result['document_uuid'])->firstOrFail();
        $pages = DocumentPage::where('document_id', $document->id)->orderBy('page_number')->get();

        $this->assertCount(2, $pages);
        $this->assertTrue($pages->every(fn ($p) => $p->extraction_source === 'text_layer'));

        // Zero OCR jobs — the ONLY thing this invariant claims. (Unrelated,
        // pre-existing 7a/7d ingest-time jobs — ProposeDocumentTags for an
        // unresolved filename, RecheckRulingsForConvenio for an active official
        // convenio — legitimately fire here too; this test is not about them.)
        Queue::assertNotPushed(OcrDocumentPages::class);
        Queue::assertNotPushed(OcrPage::class);
    }

    /** Positive control for (a): a genuinely text-less page, opted in, DOES fan out. */
    public function test_a_text_less_page_when_opted_in_dispatches_ocr_document_pages(): void
    {
        Storage::fake('s3');
        Queue::fake();

        $this->fake->extractResponse = [
            'page_count' => 1,
            'pages' => [
                ['page_number' => 1, 'text' => '', 'image_key' => 'documents/x/pages/0001.jpg', 'extraction_source' => 'ocr_pending'],
            ],
        ];

        $ingestor = new DocumentIngestor($this->fake);
        $result = $ingestor->ingest(
            $this->tmpFile('%PDF-1.4 fake scanned bytes'),
            'pacto-cultura-escaneado.pdf',
            'Navarra',
            'Navarra/pacto-cultura-escaneado.pdf',
            null,
            new VocabularyResolver,
            false,
            true,
            60,
        );

        $document = Document::where('uuid', $result['document_uuid'])->firstOrFail();
        $page = DocumentPage::where('document_id', $document->id)->first();
        $this->assertSame('ocr_pending', $page->extraction_source);

        Queue::assertPushed(OcrDocumentPages::class, fn ($job) => $job->documentId === $document->id);
    }

    /** `OcrDocumentPages` itself fans out one `OcrPage` per pending page (no OCR in the fan-out job). */
    public function test_ocr_document_pages_fans_out_one_ocr_page_job_per_pending_page(): void
    {
        $document = Document::create([
            'title' => 'Doc escaneado', 'source_filename' => 'x.pdf', 'storage_path' => 'd/x.pdf',
            'content_hash' => hash('sha256', 'x'.uniqid()), 'convenio_id' => null,
            'document_type_id' => $this->convenioType->id, 'retrieval_status' => 'active',
            'authority_level' => 'official_convenio', 'language' => 'es',
            'tagging_status' => 'under_review', 'ingested_at' => now(),
        ]);
        DocumentPage::create(['document_id' => $document->id, 'page_number' => 1, 'text' => '', 'extraction_source' => 'ocr_pending']);
        DocumentPage::create(['document_id' => $document->id, 'page_number' => 2, 'text' => 'ya tiene texto', 'extraction_source' => 'text_layer']);
        DocumentPage::create(['document_id' => $document->id, 'page_number' => 3, 'text' => '', 'extraction_source' => 'ocr_pending']);

        Queue::fake();
        (new OcrDocumentPages($document->id))->handle();

        Queue::assertPushed(OcrPage::class, 2);
        Queue::assertPushed(OcrPage::class, fn ($job) => $job->documentId === $document->id && $job->pageNumber === 1);
        Queue::assertPushed(OcrPage::class, fn ($job) => $job->documentId === $document->id && $job->pageNumber === 3);
    }

    // ---- (c) under_review / 0-chunks gate ------------------------------------

    public function test_invariant_ocrd_page_stays_under_review_and_zero_chunks_until_confirmed(): void
    {
        $document = Document::create([
            'title' => 'PACTO CULTURA NAVARRA (escaneo)',
            'source_filename' => 'pacto-cultura-navarra.pdf',
            'storage_path' => 'documents/pacto/original.pdf',
            'content_hash' => hash('sha256', 'pacto'.uniqid()),
            'convenio_id' => $this->convenio->id,
            'document_type_id' => $this->convenioType->id,
            'validity_start' => '2024-01-01',
            'validity_end' => '2027-12-31',
            'retrieval_status' => 'active',
            'authority_level' => 'official_convenio',
            'language' => 'es',
            'tagging_status' => 'under_review', // the OCR-fallback state this feature exists for
            'ingested_at' => now(),
        ]);
        $page = DocumentPage::create([
            'document_id' => $document->id,
            'page_number' => 1,
            'text' => '',
            'image_path' => 'documents/pacto/pages/0001.jpg',
            'extraction_source' => 'ocr_pending',
        ]);

        // Precondition: still under_review → the gate excludes it, 0 chunks.
        $this->assertSame(0, $this->embedSelectionCount($document->uuid));
        $this->assertSame(0, DB::table('document_chunks')->where('document_id', $document->id)->count());

        $this->fake->ocrPageResponse = [
            'text' => "Artículo 1. Objeto del pacto.\n\nEste pacto regula las condiciones laborales del personal de cultura de Navarra.",
            'layout' => 'single_column',
            'bilingual' => false,
            'quality' => 0.91,
            'quality_notes' => ['weakest_signal' => 'text_length_vs_page_area'],
            'cost_usd' => 0.0533,
            'sec_per_page' => 19.9,
            'engine' => 'claude-opus-5',
        ];

        // The OCR call itself, run DIRECTLY (bypassing the queue) — exactly the
        // shape `documents:ocr-backfill` and `OcrPage::handle()` both use.
        $result = app(OcrService::class)->ocrOnePage($page);
        $this->assertSame('ok', $result['status']);

        $page->refresh();
        $this->assertSame('ocr', $page->extraction_source);
        $this->assertStringContainsString('Objeto del pacto', $page->text);
        $this->assertSame('claude-opus-5', $page->ocr_engine);
        $this->assertEqualsWithDelta(0.91, $page->ocr_quality, 0.001);
        $this->assertEqualsWithDelta(0.0533, $page->ocr_cost_usd, 0.0001);
        $this->assertFalse($page->ocr_bilingual);

        // INVARIANT: still under_review (OcrService never touches tagging_status),
        // still 0 chunks, still excluded from the gate — genuinely unretrievable.
        $document->refresh();
        $this->assertSame('under_review', $document->tagging_status);
        $this->assertSame(0, DB::table('document_chunks')->where('document_id', $document->id)->count());
        $this->assertSame(0, $this->embedSelectionCount($document->uuid));

        // The human verify step (existing, unmodified `confirm()`).
        $document->update(['tagging_status' => 'verified']);

        // NOW the unchanged gate selects it...
        $this->assertSame(1, $this->embedSelectionCount($document->uuid));

        // ...and a real (faked) /embed call against it succeeds, proving the
        // whole path end to end (scope resolved from the convenio, exactly as
        // for any other document — resolveScope() is untouched by this sprint).
        Artisan::call('chunks:embed', ['--document' => $document->uuid]);
        $this->assertSame(1, $this->fake->embedCallCount);
        $this->assertSame($this->convenio->id, $this->fake->lastEmbedScope['convenio_id']);
    }
}

/**
 * Records exactly the two hr-ai calls this sprint's tests exercise
 * (`extract`, `ocrPage`) plus `embed` (to prove the post-verify gate
 * selection end to end) — every other method falls back to a safe empty
 * envelope, never a real network call.
 */
class FakeOcrExtractionClient extends ExtractionClient
{
    /** @var array<string,mixed> */
    public array $extractResponse = ['page_count' => 0, 'pages' => []];

    /** @var array<string,mixed> */
    public array $ocrPageResponse = [];

    public int $embedCallCount = 0;

    /** @var array<string,mixed> */
    public array $lastEmbedScope = [];

    public function extract(string $storageKey, string $documentUuid, bool $ocr = false, int $ocrPageCap = 60): array
    {
        return $this->extractResponse;
    }

    public function ocrPage(string $documentUuid, int $pageNumber, string $imageKey, string $decryptedKey, array $providerConfig): array
    {
        return $this->ocrPageResponse;
    }

    public function embed(int $documentId, string $documentUuid, string $storageKey, array $scope): array
    {
        $this->embedCallCount++;
        $this->lastEmbedScope = $scope;

        return [
            'chunks_written' => 3,
            'language_streams' => ['es' => 3, 'eu' => 0],
            'stats' => ['page_count' => 1, 'column_blocks_kept' => 1, 'furniture_blocks_stripped' => 0, 'blocks_total' => 1, 'pages_not_cleanly_split' => []],
        ];
    }
}
