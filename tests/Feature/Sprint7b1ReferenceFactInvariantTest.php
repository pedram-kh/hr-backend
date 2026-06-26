<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Convenio;
use App\Models\ConvenioJobCategory;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\ReferenceFact;
use App\Models\Sector;
use App\Models\TagEvent;
use App\Models\Territory;
use App\Models\Topic;
use App\Services\ExtractionClient;
use Database\Seeders\DocumentTypeSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Sprint 7b-1 acceptance proof (ADR-0021). The TWO non-negotiable safety
 * invariants of Structured Reference Knowledge, proven by test, plus the
 * inert-until-verified reuse and the manual create/verify path.
 *
 * INVARIANT 1 — a reference fact can NEVER outrank a convenio. Enforced TWO ways:
 *   (a) the `authority_level` enum/CHECK holds ONLY `structured_reference` — the
 *       column physically rejects official_convenio/national_law;
 *   (b) Store/UpdateReferenceFactRequest accept `structured_reference` only and
 *       REJECT (422) anything higher (reject-not-clamp, ADR-0019).
 * INVARIANT 2 — routing rides `document_type`, never content. A reference_source
 *   file produces ONLY reference_facts (zero salary_table_rows); salary:import
 *   (which filters document_type = salary_tables) does not pick it up.
 */
class Sprint7b1ReferenceFactInvariantTest extends TestCase
{
    use RefreshDatabase;

    private Territory $territory;

    private Sector $sector;

    private Convenio $convenio;

    private Topic $topic;

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
        $this->topic = Topic::create(['name' => 'Periodo de prueba', 'status' => 'approved']);
    }

    // ---- INVARIANT 1a — the column physically rejects a higher authority ----

    public function test_invariant_1a_authority_column_cannot_store_official_convenio(): void
    {
        $this->expectException(QueryException::class);

        // Bypass the model default by writing the column directly — the DB enum
        // CHECK must reject anything but `structured_reference`.
        DB::table('reference_facts')->insert([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'convenio_id' => $this->convenio->id,
            'value' => 'intento de autoridad alta',
            'authority_level' => 'official_convenio', // ← illegal at the column
            'source' => 'admin_manual',
            'status' => 'needs_review',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // ---- INVARIANT 1b — the request rejects (422), nothing written ----------

    public function test_invariant_1b_store_rejects_higher_authority_422_and_writes_nothing(): void
    {
        $editor = $this->knowledgeEditor();

        $this->postJson('/admin/reference-facts', [
            'convenio_id' => $this->convenio->id,
            'topic_id' => $this->topic->id,
            'value' => 'periodo de prueba 90/75 días',
            'authority_level' => 'official_convenio', // ← rejected by the FormRequest
        ], $this->auth($editor))
            ->assertStatus(422)
            ->assertJsonValidationErrors('authority_level');

        $this->assertSame(0, ReferenceFact::count(), 'nothing is written on a rejected authority');
    }

    public function test_invariant_1b_update_rejects_higher_authority_422(): void
    {
        $editor = $this->knowledgeEditor();
        $fact = $this->createFactViaApi($editor);

        $this->patchJson("/admin/reference-facts/{$fact}", [
            'authority_level' => 'national_law',
        ], $this->auth($editor))
            ->assertStatus(422)
            ->assertJsonValidationErrors('authority_level');

        $this->assertSame('structured_reference', ReferenceFact::where('uuid', $fact)->value('authority_level'));
    }

    // ---- INVARIANT 2 — routing rides document_type, never content -----------

    public function test_invariant_2_reference_source_ingest_writes_no_salary_rows_and_salary_import_ignores_it(): void
    {
        Storage::fake('s3');

        // A reference_source .xlsx (the Alhambra fixture shape) ingested via the
        // reference path: mock hr-ai /read-structured (no network).
        $ingestor = new \App\Services\DocumentIngestor($this->fakeReader());
        $tmp = tempnam(sys_get_temp_dir(), 'ref').'.xlsx';
        file_put_contents($tmp, 'PK-fake-xlsx-bytes');

        $result = $ingestor->ingest(
            $tmp,
            'Tablas_acuerdo_parcial_Alhambra.xlsx',
            null,
            'Tablas_acuerdo_parcial_Alhambra.xlsx',
            null,
            new \App\Support\VocabularyResolver,
            asReference: true,
        );

        $doc = Document::where('uuid', $result['document_uuid'])->with('documentType')->first();
        $this->assertSame('reference_source', $doc->documentType->code, 'a reference upload is tagged reference_source');
        $this->assertTrue($result['as_reference']);
        // Content was read + stored as display pages (never embedded).
        $this->assertGreaterThan(0, $doc->pages()->count());
        $this->assertSame(0, DB::table('document_chunks')->where('document_id', $doc->id)->count(), 'reference sources are never embedded');
        // The reference path NEVER writes a salary row.
        $this->assertSame(0, DB::table('salary_table_rows')->count());
        $this->assertSame(0, DB::table('salary_tables')->count());

        // salary:import filters document_type = salary_tables → it must ignore
        // the reference_source doc entirely (no rows appear).
        $this->artisan('salary:import')->assertExitCode(0);
        $this->assertSame(0, DB::table('salary_table_rows')->count(), 'salary:import does not pick up a reference_source file');

        @unlink($tmp);
    }

    public function test_creating_facts_never_touches_salary_tables(): void
    {
        $editor = $this->knowledgeEditor();
        $this->createFactViaApi($editor);

        $this->assertSame(1, ReferenceFact::count());
        $this->assertSame(0, DB::table('salary_table_rows')->count());
        $this->assertSame(0, DB::table('salary_tables')->count());
    }

    // ---- Inert until verified + append-only provenance (the 7a/ADR-0020 spine)

    public function test_fact_lands_needs_review_then_human_verify_flips_it_with_appended_provenance(): void
    {
        $editor = $this->knowledgeEditor();
        $uuid = $this->createFactViaApi($editor);

        $fact = ReferenceFact::where('uuid', $uuid)->first();
        $this->assertSame('needs_review', $fact->status, 'a new fact is inert (not answerable) until verified');
        $this->assertSame('admin_manual', $fact->source);
        $this->assertNull($fact->verified_at);

        // Provenance: a created event exists; verify appends (never rewrites).
        $createdEvents = TagEvent::where('entity_type', 'reference_fact')->where('entity_id', $fact->id)->count();
        $this->assertGreaterThan(0, $createdEvents);

        $this->postJson("/admin/reference-facts/{$uuid}/verify", [], $this->auth($editor))
            ->assertOk()->assertJsonPath('fact_status', 'verified');

        $fact->refresh();
        $this->assertSame('verified', $fact->status);
        $this->assertNotNull($fact->verified_by);
        $this->assertNotNull($fact->verified_at);

        // Append-only: the verify event is ADDED on top (count grew, none rewritten).
        $afterEvents = TagEvent::where('entity_type', 'reference_fact')->where('entity_id', $fact->id)->get();
        $this->assertGreaterThan($createdEvents, $afterEvents->count());
        $this->assertNotNull($afterEvents->firstWhere('new_value', 'verified'));
        $this->assertNotNull($afterEvents->firstWhere('new_value', 'needs_review'));
    }

    // ---- Scope discipline ---------------------------------------------------

    public function test_territory_and_sector_are_prohibited_from_the_request(): void
    {
        $editor = $this->knowledgeEditor();

        $this->postJson('/admin/reference-facts', [
            'convenio_id' => $this->convenio->id,
            'value' => 'x',
            'territory_id' => $this->territory->id, // ← derived, never client-set
        ], $this->auth($editor))->assertStatus(422)->assertJsonValidationErrors('territory_id');
    }

    public function test_scope_affecting_edit_requires_confirm_else_409(): void
    {
        $editor = $this->knowledgeEditor();
        $uuid = $this->createFactViaApi($editor);
        $other = Convenio::create(['numero' => '31000999', 'name' => 'Otro', 'territory_id' => $this->territory->id, 'sector_id' => $this->sector->id]);

        // Changing the convenio (scope) without confirm → 409.
        $this->patchJson("/admin/reference-facts/{$uuid}", [
            'convenio_id' => $other->id,
        ], $this->auth($editor))->assertStatus(409)->assertJsonPath('scope_affecting', true);

        // With confirm → applied.
        $this->patchJson("/admin/reference-facts/{$uuid}", [
            'convenio_id' => $other->id, 'confirm_scope_change' => true,
        ], $this->auth($editor))->assertOk();
        $this->assertSame($other->id, ReferenceFact::where('uuid', $uuid)->value('convenio_id'));
    }

    public function test_job_category_must_belong_to_the_convenio(): void
    {
        $editor = $this->knowledgeEditor();
        $other = Convenio::create(['numero' => '31000888', 'name' => 'Otro', 'territory_id' => $this->territory->id, 'sector_id' => $this->sector->id]);
        $foreignCat = ConvenioJobCategory::create(['convenio_id' => $other->id, 'name' => 'Grupo X']);

        $this->postJson('/admin/reference-facts', [
            'convenio_id' => $this->convenio->id,
            'job_category_id' => $foreignCat->id, // ← belongs to a different convenio
            'value' => 'x',
        ], $this->auth($editor))->assertStatus(422);

        $this->assertSame(0, ReferenceFact::count());
    }

    // ---- Authorization (Q6 — knowledge.edit gates writes) -------------------

    public function test_writes_require_knowledge_edit_reads_are_open(): void
    {
        $auditor = Admin::create(['email' => 'aud@example.com', 'full_name' => 'Aud', 'status' => 'active']);
        $auditor->assignRole('auditor');

        // Read is open.
        $this->getJson('/admin/reference-facts', $this->auth($auditor))->assertOk();

        // Write without knowledge.edit → 403.
        $this->postJson('/admin/reference-facts', [
            'convenio_id' => $this->convenio->id, 'value' => 'x',
        ], $this->auth($auditor))->assertStatus(403);
    }

    // ---- helpers ------------------------------------------------------------

    private function createFactViaApi(Admin $editor): string
    {
        return $this->postJson('/admin/reference-facts', [
            'convenio_id' => $this->convenio->id,
            'topic_id' => $this->topic->id,
            'value' => 'periodo de prueba 90/75 días',
            'raw_values' => ['texto' => 'El periodo de prueba será de 90 días para el grupo 1 y 75 para el resto.'],
            'validity_start' => '2024-01-01',
            'validity_end' => '2027-12-31',
        ], $this->auth($editor))->assertStatus(201)->json('uuid');
    }

    private function knowledgeEditor(): Admin
    {
        $editor = Admin::create(['email' => 'ke@example.com', 'full_name' => 'KE', 'status' => 'active']);
        $editor->assignRole('knowledge_editor');

        return $editor;
    }

    /** A reader whose hr-ai /read-structured call is stubbed (no network). */
    private function fakeReader(): ExtractionClient
    {
        return new class extends ExtractionClient
        {
            public function __construct() {}

            public function readStructured(string $storageKey, string $documentUuid, string $format): array
            {
                return ['format' => $format, 'pages' => [
                    ['page_number' => 1, 'label' => 'smi26', 'text' => 'SMI 2026 | 1184 €/mes', 'locator' => 'sheet:smi26'],
                    ['page_number' => 2, 'label' => 'tablas', 'text' => 'Grupo 1 | 21000', 'locator' => 'sheet:tablas'],
                ]];
            }
        };
    }

    /** @return array<string,string> */
    private function auth(Admin $admin): array
    {
        $this->app['auth']->forgetGuards();
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        return ['Authorization' => 'Bearer '.$admin->createToken('test')->plainTextToken, 'Accept' => 'application/json'];
    }
}
