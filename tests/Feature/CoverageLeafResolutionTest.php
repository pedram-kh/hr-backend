<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Convenio;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\ReferenceFact;
use App\Models\Sector;
use App\Models\Territory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Sprint 8 follow-up (found live, eyes-on 2026-09-10): clicking a coverage
 * leaf (e.g. Navarra › Hostelería › Prosa ✗) opened nothing — no leaf in the
 * coverage lens ever carried a `doc_uuid`/`fact_uuid`, and `CoveragePage.tsx`
 * passed a no-op `onOpenDocument`. Fixed on both ends (`CorpusCoverageService`
 * now returns the underlying document/fact identifier per cell;
 * `HierarchyController::coverageCellLeaves()` surfaces it, or a `fix_link`
 * when genuinely nothing exists; `CoveragePage.tsx` wires real handlers).
 *
 * This test is the hard guarantee: every GAP leaf (prose/salary/facts) in the
 * coverage lens resolves to a `doc_uuid`, a `fact_uuid`, or a `fix_link` —
 * never none of the three. `rulings` is exempt by design (not a gap at all —
 * see `CorpusCoverageService::rulingsCell()`'s own doc-comment) and is
 * checked separately for the opposite property: no badge, ever.
 */
class CoverageLeafResolutionTest extends TestCase
{
    use RefreshDatabase;

    private Territory $territory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\DocumentTypeSeeder::class);
        $this->seed(\Database\Seeders\RoleSeeder::class);
        $this->territory = Territory::create(['code' => '31', 'name' => 'Navarra', 'level' => 'provincial', 'aliases' => []]);
    }

    private function auth(): array
    {
        $admin = Admin::create(['email' => 'coverage-leaf-'.uniqid().'@example.com', 'full_name' => 'Tester', 'status' => 'active']);
        $admin->assignRole('super_admin');

        return ['Authorization' => 'Bearer '.$admin->createToken('test')->plainTextToken, 'Accept' => 'application/json'];
    }

    private function leavesFor(Convenio $convenio): array
    {
        $resp = $this->getJson(
            "/admin/hierarchy/children?lens=coverage&parent=t:{$this->territory->id}|c:{$convenio->id}",
            $this->auth(),
        );
        $resp->assertStatus(200);

        return collect($resp->json('nodes'))->keyBy(fn ($n) => Str::afterLast($n['key'], ':'))->all();
    }

    /** Every gap leaf resolves to something real — never nothing. */
    private function assertResolvesToSomething(array $leaf, string $cellName): void
    {
        $resolved = ($leaf['doc_uuid'] ?? null) !== null
            || ($leaf['fact_uuid'] ?? null) !== null
            || ($leaf['fix_link'] ?? null) !== null;

        $this->assertTrue($resolved, "leaf '{$cellName}' resolved to nothing: ".json_encode($leaf));
    }

    public function test_prose_scan_no_text_leaf_opens_the_blocking_document(): void
    {
        $sector = Sector::create(['name' => 'Hostelería', 'aliases' => []]);
        $convenio = Convenio::create(['numero' => '31TESTX001', 'name' => 'Test Hosteleria', 'territory_id' => $this->territory->id, 'sector_id' => $sector->id]);
        $doc = Document::create([
            'uuid' => (string) Str::uuid(), 'title' => 'Scan doc', 'storage_path' => 'x/scan.pdf',
            'convenio_id' => $convenio->id, 'document_type_id' => DocumentType::where('code', 'convenio_text')->value('id'),
            'retrieval_status' => 'active', 'authority_level' => 'official_convenio', 'language' => 'es', 'tagging_status' => 'verified',
        ]);
        DB::table('document_pages')->insert(['document_id' => $doc->id, 'page_number' => 1, 'text' => '', 'created_at' => now(), 'updated_at' => now()]);

        $leaves = $this->leavesFor($convenio);
        $this->assertSame('SCAN_NO_TEXT', $leaves['prose']['gap_kind']);
        $this->assertSame($doc->uuid, $leaves['prose']['doc_uuid']);
        $this->assertResolvesToSomething($leaves['prose'], 'prose');
    }

    public function test_prose_under_review_leaf_opens_the_blocking_document(): void
    {
        $sector = Sector::create(['name' => 'Hostelería', 'aliases' => []]);
        $convenio = Convenio::create(['numero' => '31TESTX002', 'name' => 'Test Hosteleria 2', 'territory_id' => $this->territory->id, 'sector_id' => $sector->id]);
        $doc = Document::create([
            'uuid' => (string) Str::uuid(), 'title' => 'Under review doc', 'storage_path' => 'x/ur.pdf',
            'convenio_id' => $convenio->id, 'document_type_id' => DocumentType::where('code', 'convenio_text')->value('id'),
            'retrieval_status' => 'active', 'authority_level' => 'official_convenio', 'language' => 'es', 'tagging_status' => 'under_review',
        ]);
        DB::table('document_pages')->insert(['document_id' => $doc->id, 'page_number' => 1, 'text' => 'real text', 'created_at' => now(), 'updated_at' => now()]);

        $leaves = $this->leavesFor($convenio);
        $this->assertSame('UNDER_REVIEW_SCOPE', $leaves['prose']['gap_kind']);
        $this->assertSame($doc->uuid, $leaves['prose']['doc_uuid']);
        $this->assertResolvesToSomething($leaves['prose'], 'prose');
    }

    public function test_prose_expired_no_successor_leaf_opens_the_historical_document(): void
    {
        $sector = Sector::create(['name' => 'Hostelería', 'aliases' => []]);
        $convenio = Convenio::create(['numero' => '31TESTX003', 'name' => 'Test Hosteleria 3', 'territory_id' => $this->territory->id, 'sector_id' => $sector->id]);
        $doc = Document::create([
            'uuid' => (string) Str::uuid(), 'title' => 'Historical doc', 'storage_path' => 'x/hist.pdf',
            'convenio_id' => $convenio->id, 'document_type_id' => DocumentType::where('code', 'convenio_text')->value('id'),
            'retrieval_status' => 'historical', 'authority_level' => 'official_convenio', 'language' => 'es', 'tagging_status' => 'verified',
        ]);

        $leaves = $this->leavesFor($convenio);
        $this->assertSame('EXPIRED_NO_SUCCESSOR', $leaves['prose']['gap_kind']);
        $this->assertSame($doc->uuid, $leaves['prose']['doc_uuid']);
        $this->assertResolvesToSomething($leaves['prose'], 'prose');
    }

    public function test_prose_with_no_document_at_all_falls_back_to_fix_link(): void
    {
        $sector = Sector::create(['name' => 'Hostelería', 'aliases' => []]);
        $convenio = Convenio::create(['numero' => '31TESTX004', 'name' => 'Test Hosteleria 4', 'territory_id' => $this->territory->id, 'sector_id' => $sector->id]);

        $leaves = $this->leavesFor($convenio);
        $this->assertNull($leaves['prose']['doc_uuid'] ?? null);
        $this->assertNotNull($leaves['prose']['fix_link']);
        $this->assertStringContainsString("convenio={$convenio->id}", $leaves['prose']['fix_link']);
        $this->assertResolvesToSomething($leaves['prose'], 'prose');
    }

    public function test_salary_pdf_not_imported_leaf_opens_the_pdf_document(): void
    {
        $sector = Sector::create(['name' => 'Hostelería', 'aliases' => []]);
        $convenio = Convenio::create(['numero' => '31TESTX005', 'name' => 'Test Hosteleria 5', 'territory_id' => $this->territory->id, 'sector_id' => $sector->id]);
        $doc = Document::create([
            'uuid' => (string) Str::uuid(), 'title' => 'Salary PDF', 'storage_path' => 'x/salary.pdf',
            'convenio_id' => $convenio->id, 'document_type_id' => DocumentType::where('code', 'salary_tables')->value('id'),
            'retrieval_status' => 'active', 'authority_level' => 'official_convenio', 'language' => 'es', 'tagging_status' => 'verified',
        ]);

        $leaves = $this->leavesFor($convenio);
        $this->assertSame('SALARY_PDF_NOT_IMPORTED', $leaves['salary']['gap_kind']);
        $this->assertSame($doc->uuid, $leaves['salary']['doc_uuid']);
        $this->assertResolvesToSomething($leaves['salary'], 'salary');
    }

    public function test_salary_with_no_source_gets_a_real_code_not_unclassified(): void
    {
        $sector = Sector::create(['name' => 'Hostelería', 'aliases' => []]);
        $convenio = Convenio::create(['numero' => '31TESTX006', 'name' => 'Test Hosteleria 6', 'territory_id' => $this->territory->id, 'sector_id' => $sector->id]);

        $leaves = $this->leavesFor($convenio);
        $this->assertSame('NO_SALARY_SOURCE', $leaves['salary']['gap_kind']);
        $this->assertNotSame('coverage_gap_unclassified', $leaves['salary']['gap_kind']);
        $this->assertNotNull($leaves['salary']['fix_link']);
        $this->assertResolvesToSomething($leaves['salary'], 'salary');
    }

    public function test_facts_needs_review_leaf_opens_the_proposed_fact(): void
    {
        $sector = Sector::create(['name' => 'Hostelería', 'aliases' => []]);
        $convenio = Convenio::create(['numero' => '31TESTX007', 'name' => 'Test Hosteleria 7', 'territory_id' => $this->territory->id, 'sector_id' => $sector->id]);
        $fact = ReferenceFact::create([
            'uuid' => (string) Str::uuid(), 'convenio_id' => $convenio->id, 'value' => '25 días',
            'authority_level' => 'structured_reference', 'source' => 'ai_agent', 'status' => 'needs_review',
        ]);

        $leaves = $this->leavesFor($convenio);
        $this->assertSame('FACT_NEEDS_REVIEW', $leaves['facts']['gap_kind']);
        $this->assertSame($fact->uuid, $leaves['facts']['fact_uuid']);
        $this->assertResolvesToSomething($leaves['facts'], 'facts');
    }

    public function test_facts_with_no_fact_at_all_falls_back_to_fix_link(): void
    {
        $sector = Sector::create(['name' => 'Hostelería', 'aliases' => []]);
        $convenio = Convenio::create(['numero' => '31TESTX008', 'name' => 'Test Hosteleria 8', 'territory_id' => $this->territory->id, 'sector_id' => $sector->id]);

        $leaves = $this->leavesFor($convenio);
        $this->assertNull($leaves['facts']['fact_uuid'] ?? null);
        $this->assertNotNull($leaves['facts']['fix_link']);
        $this->assertResolvesToSomething($leaves['facts'], 'facts');
    }

    /** Rulings is the opposite guarantee: never a gap badge, ever — a neutral dash. */
    public function test_rulings_uncovered_is_never_a_gap_badge(): void
    {
        $sector = Sector::create(['name' => 'Hostelería', 'aliases' => []]);
        $convenio = Convenio::create(['numero' => '31TESTX009', 'name' => 'Test Hosteleria 9', 'territory_id' => $this->territory->id, 'sector_id' => $sector->id]);

        $leaves = $this->leavesFor($convenio);
        $this->assertNull($leaves['rulings']['gap_kind']);
        $this->assertSame('—', $leaves['rulings']['meta']);
    }

    public function test_rulings_covered_opens_the_ruling_document(): void
    {
        $sector = Sector::create(['name' => 'Hostelería', 'aliases' => []]);
        $convenio = Convenio::create(['numero' => '31TESTX010', 'name' => 'Test Hosteleria 10', 'territory_id' => $this->territory->id, 'sector_id' => $sector->id]);
        $doc = Document::create([
            'uuid' => (string) Str::uuid(), 'title' => 'Ruling', 'storage_path' => 'x/ruling.pdf',
            'convenio_id' => $convenio->id, 'document_type_id' => DocumentType::where('code', 'internal_hr_ruling')->value('id'),
            'retrieval_status' => 'active', 'authority_level' => 'internal_hr_ruling', 'language' => 'es', 'tagging_status' => 'verified',
        ]);

        $leaves = $this->leavesFor($convenio);
        $this->assertSame('✓', $leaves['rulings']['meta']);
        $this->assertSame($doc->uuid, $leaves['rulings']['doc_uuid']);
    }
}
