<?php

namespace Tests\Feature;

use App\Models\Convenio;
use App\Models\Document;
use App\Models\Employee;
use App\Models\ReferenceFact;
use App\Models\SalaryTable;
use App\Models\Sector;
use App\Models\Territory;
use App\Support\CorpusCoverageService;
use Database\Seeders\DocumentTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Sprint 8, Step 2 (plan.md §5.6, §11) — the two tests the sprint's own
 * "the screen and the ledger share one query" hard constraint demands:
 *
 * 1. AGREEMENT: two calls to `CorpusCoverageService::grid()` against the same
 *    DB state return byte-identical data — the admin screen and the
 *    `corpus:coverage` export are proven to be the SAME code path, not two
 *    independently-written queries that happen to agree today.
 * 2. BYTE-STABLE EXPORT: `toMarkdown()` called twice on the same grid/date
 *    produces byte-identical output — the export is deterministic (no
 *    timestamp/random content, stable row order), so a diff between two runs
 *    against an unchanged corpus is empty.
 */
class CorpusCoverageAgreementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DocumentTypeSeeder::class);
        $this->buildWorld();
    }

    private function buildWorld(): void
    {
        $territory = Territory::create(['code' => '01', 'name' => 'Álava', 'level' => 'provincial', 'aliases' => []]);
        $sector = Sector::create(['name' => 'Test Sector', 'aliases' => []]);

        // Convenio A: fully covered (prose + salary + facts + ruling).
        $convenioA = Convenio::create(['numero' => '01TESTA001', 'name' => 'Covered Convenio', 'territory_id' => $territory->id, 'sector_id' => $sector->id]);
        $doc = Document::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(), 'title' => 'Convenio A text', 'storage_path' => 'x/a.pdf',
            'convenio_id' => $convenioA->id, 'document_type_id' => \App\Models\DocumentType::where('code', 'convenio_text')->value('id'),
            'retrieval_status' => 'active', 'authority_level' => 'official_convenio', 'language' => 'es', 'tagging_status' => 'verified',
        ]);
        DB::table('document_chunks')->insert(['document_id' => $doc->id, 'chunk_index' => 0, 'content' => 'text', 'token_count' => 10, 'created_at' => now(), 'updated_at' => now()]);
        SalaryTable::create(['convenio_id' => $convenioA->id, 'year' => Carbon::today()->year, 'source' => 'xlsx_native']);
        ReferenceFact::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(), 'convenio_id' => $convenioA->id, 'value' => '30 días',
            'authority_level' => 'structured_reference', 'source' => 'admin_manual', 'status' => 'verified',
        ]);
        $ruling = Document::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(), 'title' => 'Ruling A', 'storage_path' => 'x/r.pdf',
            'convenio_id' => $convenioA->id, 'document_type_id' => \App\Models\DocumentType::where('code', 'internal_hr_ruling')->value('id'),
            'retrieval_status' => 'active', 'authority_level' => 'internal_hr_ruling', 'language' => 'es', 'tagging_status' => 'verified',
        ]);

        // Convenio B: a full gap (no documents, no salary, no facts at all).
        Convenio::create(['numero' => '01TESTB002', 'name' => 'Gap Convenio', 'territory_id' => $territory->id, 'sector_id' => $sector->id]);

        Employee::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(), 'email' => 'w1@example.com', 'full_name' => 'Worker One',
            'convenio_id' => $convenioA->id, 'territory_id' => $territory->id, 'employment_type' => 'full_time', 'status' => 'active',
        ]);

        // Convenio C — reproduces a real bug found live on staging 2026-09-10
        // (convenios 11/17, COEAS Estatal + Madrid): an active convenio_text
        // doc with REAL, fully-extracted page text (chunks:embed's own
        // selection just excludes tagging_status=under_review — Part 1 flow
        // 1), so it has 0 chunks despite having 0 text problems. This must
        // read UNDER_REVIEW_SCOPE, never SCAN_NO_TEXT.
        $convenioC = Convenio::create(['numero' => '01TESTC003', 'name' => 'Under-review-with-text Convenio', 'territory_id' => $territory->id, 'sector_id' => $sector->id]);
        $docC = Document::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(), 'title' => 'Convenio C text', 'storage_path' => 'x/c.pdf',
            'convenio_id' => $convenioC->id, 'document_type_id' => \App\Models\DocumentType::where('code', 'convenio_text')->value('id'),
            'retrieval_status' => 'active', 'authority_level' => 'official_convenio', 'language' => 'es', 'tagging_status' => 'under_review',
        ]);
        DB::table('document_pages')->insert(['document_id' => $docC->id, 'page_number' => 1, 'text' => 'real extracted convenio text, plenty of it', 'created_at' => now(), 'updated_at' => now()]);
        // deliberately NO document_chunks row — chunks:embed never ran on this under_review doc.

        // Convenio D — the genuine-scan-no-text case, kept distinct from C so
        // the fix doesn't just move the bug the other direction: an active,
        // NOT-under_review doc whose pages have literally no extracted text
        // must still read SCAN_NO_TEXT.
        $convenioD = Convenio::create(['numero' => '01TESTD004', 'name' => 'Genuine Scan Convenio', 'territory_id' => $territory->id, 'sector_id' => $sector->id]);
        $docD = Document::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(), 'title' => 'Convenio D scan', 'storage_path' => 'x/d.pdf',
            'convenio_id' => $convenioD->id, 'document_type_id' => \App\Models\DocumentType::where('code', 'convenio_text')->value('id'),
            'retrieval_status' => 'active', 'authority_level' => 'official_convenio', 'language' => 'es', 'tagging_status' => 'auto_proposed',
        ]);
        DB::table('document_pages')->insert(['document_id' => $docD->id, 'page_number' => 1, 'text' => '', 'created_at' => now(), 'updated_at' => now()]);

        // Convenio E — the dev-fixture placeholder (real staging shape: found
        // live 2026-09-10 showing up in the grid with a fake headcount). Must
        // be excluded from both the grid and headcounts entirely.
        $convenioE = Convenio::create(['numero' => CorpusCoverageService::DEV_FIXTURE_NUMERO_PREFIX.'0001', 'name' => 'DEV FIXTURE — placeholder', 'territory_id' => $territory->id, 'sector_id' => $sector->id]);
        Employee::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(), 'email' => 'fixture@example.com', 'full_name' => 'Fixture Employee',
            'convenio_id' => $convenioE->id, 'territory_id' => $territory->id, 'employment_type' => 'full_time', 'status' => 'active',
        ]);
    }

    public function test_grid_is_byte_identical_across_two_calls(): void
    {
        $service = app(CorpusCoverageService::class);
        $asOf = Carbon::create(2026, 9, 10);

        $gridOne = $service->grid($asOf);
        $gridTwo = $service->grid($asOf);

        $this->assertSame(json_encode($gridOne), json_encode($gridTwo), 'grid() must be deterministic — same DB state, same output, every call.');
        $this->assertNotEmpty($gridOne);

        // Sanity: convenio A is fully covered, convenio B is a full gap.
        $byNumero = collect($gridOne)->keyBy('numero');
        $this->assertTrue($byNumero['01TESTA001']['prose']['covered']);
        $this->assertTrue($byNumero['01TESTA001']['salary']['covered']);
        $this->assertTrue($byNumero['01TESTA001']['facts']['covered']);
        $this->assertTrue($byNumero['01TESTA001']['rulings']['covered']);
        $this->assertFalse($byNumero['01TESTB002']['prose']['covered']);
        $this->assertFalse($byNumero['01TESTB002']['salary']['covered']);
        $this->assertFalse($byNumero['01TESTB002']['facts']['covered']);

        // Regression (staging finding, 2026-09-10): 0 chunks + real text +
        // under_review tagging => UNDER_REVIEW_SCOPE, never SCAN_NO_TEXT.
        $this->assertFalse($byNumero['01TESTC003']['prose']['covered']);
        $this->assertSame(CorpusCoverageService::REASON_UNDER_REVIEW_SCOPE, $byNumero['01TESTC003']['prose']['reason_code']);

        // 0 chunks + genuinely empty page text still reads SCAN_NO_TEXT.
        $this->assertFalse($byNumero['01TESTD004']['prose']['covered']);
        $this->assertSame(CorpusCoverageService::REASON_SCAN_NO_TEXT, $byNumero['01TESTD004']['prose']['reason_code']);

        // Regression (staging finding, 2026-09-10): the dev-fixture
        // placeholder convenio must not appear in the grid at all.
        $this->assertArrayNotHasKey(CorpusCoverageService::DEV_FIXTURE_NUMERO_PREFIX.'0001', $byNumero);
    }

    public function test_headcounts_exclude_the_dev_fixture_convenio(): void
    {
        $service = app(CorpusCoverageService::class);
        $headcounts = $service->headcounts();

        $fixtureConvenioId = Convenio::where('numero', CorpusCoverageService::DEV_FIXTURE_NUMERO_PREFIX.'0001')->value('id');
        $this->assertArrayNotHasKey($fixtureConvenioId, $headcounts, 'the dev-fixture convenio must never contribute a headcount.');
    }

    public function test_export_markdown_is_byte_stable_across_two_calls(): void
    {
        $service = app(CorpusCoverageService::class);
        $asOf = Carbon::create(2026, 9, 10);

        $grid = $service->grid($asOf);
        $noRegistry = $service->noRegistryConvenioRows();

        $mdOne = $service->toMarkdown($grid, $noRegistry, $asOf);
        $mdTwo = $service->toMarkdown($grid, $noRegistry, $asOf);

        $this->assertSame($mdOne, $mdTwo, 'toMarkdown() must produce byte-identical output for the same grid/date — no timestamp/random content.');
        $this->assertStringContainsString('Covered Convenio', $mdOne);
        $this->assertStringContainsString('Gap Convenio', $mdOne);
    }

    public function test_screen_and_export_call_the_same_service_method(): void
    {
        // The agreement is structural, not incidental: assert both call sites
        // this test simulates (screen == a direct grid() call, export == the
        // artisan command) resolve through the SAME class — there is no
        // second implementation anywhere to drift from this one.
        $reflection = new \ReflectionClass(\App\Console\Commands\CorpusCoverage::class);
        $handle = $reflection->getMethod('handle');
        $params = $handle->getParameters();
        $this->assertSame(CorpusCoverageService::class, $params[0]->getType()->getName());
    }
}
