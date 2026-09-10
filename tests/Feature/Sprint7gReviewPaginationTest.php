<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Convenio;
use App\Models\ConvenioGroup;
use App\Models\Document;
use App\Models\DocumentReviewTask;
use App\Models\DocumentType;
use App\Models\ReferenceFact;
use App\Models\Sector;
use App\Models\Territory;
use App\Models\Topic;
use App\Models\VocabularyProposal;
use Database\Seeders\DocumentTypeSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Sprint 7g Item 2 — Review surfaces at real corpus size.
 *
 * Two independent things, proven together because they share fixtures:
 *
 *   1. PAGINATION + VISIBLE TOTAL on the tabs that did not already have it
 *      (Vocabulary proposals, Expiry) — the exact gap the Documents page had
 *      before Sprint 7e (a silent `->get()` cap), fixed the same way
 *      (`->paginate(50)`, Laravel's standard envelope). The Reference-facts
 *      queue already paginated server-side (`ReferenceFactController::index`,
 *      Sprint 7b-2) — only the frontend was silently reading page 1; nothing
 *      to prove here beyond the id/excerpt addition below.
 *   2. FACT ID + FIRST LINE OF `source_excerpt` INLINE on the Reference-facts
 *      list and the Groups tab's fact lists, so a reviewer can identify and
 *      sanity-check a fact from the list without opening it.
 *
 * Nothing here changes when or whether anything escalates, retrieves,
 * synthesises, grounds, or answers — every assertion below is about a list
 * ENDPOINT's shape, never a decision.
 */
class Sprint7gReviewPaginationTest extends TestCase
{
    use RefreshDatabase;

    private Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(DocumentTypeSeeder::class);

        $this->admin = Admin::create(['email' => 'kc7g2@example.com', 'full_name' => 'KC 7g-2', 'status' => 'active']);
        $this->admin->assignRole('super_admin');
    }

    /** @return array<string,string> */
    private function auth(): array
    {
        $this->app['auth']->forgetGuards();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return ['Authorization' => 'Bearer '.$this->admin->createToken('t')->plainTextToken, 'Accept' => 'application/json'];
    }

    // ---- Vocabulary proposals: now paginates -----------------------------

    public function test_vocabulary_proposals_paginate_at_50_with_a_visible_total(): void
    {
        for ($i = 0; $i < 55; $i++) {
            VocabularyProposal::create([
                'facet' => 'sector', 'proposed_value' => "Sector de prueba {$i}",
                'status' => 'proposed', 'proposed_by_source' => 'ai_agent',
            ]);
        }

        $page1 = $this->getJson('/admin/vocabulary-proposals?status=proposed', $this->auth())->assertStatus(200);
        $page1->assertJsonPath('proposals.total', 55)
            ->assertJsonPath('proposals.current_page', 1)
            ->assertJsonPath('proposals.last_page', 2)
            ->assertJsonCount(50, 'proposals.data');

        $page2 = $this->getJson('/admin/vocabulary-proposals?status=proposed&page=2', $this->auth())->assertStatus(200);
        $page2->assertJsonPath('proposals.current_page', 2)
            ->assertJsonCount(5, 'proposals.data');
    }

    // ---- Expiry queue: now paginates --------------------------------------

    public function test_expiry_queue_paginates_at_50_with_a_visible_total(): void
    {
        $territory = Territory::create(['code' => '01', 'name' => 'Araba', 'level' => 'provincial', 'aliases' => []]);
        $sector = Sector::create(['name' => 'Comercio 7g2', 'aliases' => []]);
        $convenio = Convenio::create([
            'numero' => '01000155012021', 'name' => 'Comercio Araba 7g2',
            'territory_id' => $territory->id, 'sector_id' => $sector->id,
        ]);
        $type = DocumentType::where('code', 'convenio_text')->first();

        for ($i = 0; $i < 52; $i++) {
            $doc = Document::create([
                'title' => "Convenio expirando {$i}", 'source_filename' => "f{$i}.pdf", 'storage_path' => "docs/f{$i}.pdf",
                'content_hash' => hash('sha256', "expiry-{$i}"), 'convenio_id' => $convenio->id, 'document_type_id' => $type->id,
                'validity_start' => '2024-01-01', 'validity_end' => '2026-10-01', 'language' => 'es',
                'retrieval_status' => 'active', 'authority_level' => 'official_convenio', 'tagging_status' => 'verified',
            ]);
            DocumentReviewTask::create([
                'document_id' => $doc->id, 'type' => 'expiry', 'reason' => null,
                'status' => 'open', 'due_date' => '2026-10-01',
            ]);
        }

        $page1 = $this->getJson('/admin/review/expiry', $this->auth())->assertStatus(200);
        $page1->assertJsonPath('tasks.total', 52)
            ->assertJsonPath('tasks.current_page', 1)
            ->assertJsonPath('tasks.last_page', 2)
            ->assertJsonCount(50, 'tasks.data');

        $page2 = $this->getJson('/admin/review/expiry?page=2', $this->auth())->assertStatus(200);
        $page2->assertJsonPath('tasks.current_page', 2)
            ->assertJsonCount(2, 'tasks.data');
    }

    // ---- Reference-facts list: id + source_excerpt inline -----------------

    public function test_reference_facts_list_rows_carry_the_numeric_id_and_source_excerpt(): void
    {
        $territory = Territory::create(['code' => '31', 'name' => 'Navarra 7g2', 'level' => 'provincial', 'aliases' => []]);
        $sector = Sector::create(['name' => 'Hostelería 7g2', 'aliases' => []]);
        $convenio = Convenio::create([
            'numero' => '31101815012099', 'name' => 'Navarra Hostelería 7g2',
            'territory_id' => $territory->id, 'sector_id' => $sector->id,
        ]);
        $topic = Topic::firstOrCreate(['name' => 'periodo de prueba 7g2'], ['status' => 'approved']);

        $fact = ReferenceFact::create([
            'convenio_id' => $convenio->id, 'topic_id' => $topic->id, 'group_label' => 'Grupo 1',
            'value' => '90 días', 'authority_level' => ReferenceFact::AUTHORITY_LEVEL,
            'source' => 'ai_agent', 'status' => 'needs_review',
            'source_excerpt' => "Periodo de prueba: noventa días para el Grupo 1.\nSegunda línea del contexto, no debe aparecer inline.",
        ]);

        $res = $this->getJson('/admin/reference-facts?queue=true', $this->auth())->assertStatus(200);
        $res->assertJsonPath('facts.data.0.id', $fact->id)
            ->assertJsonPath('facts.data.0.source_excerpt', $fact->source_excerpt);
    }

    // ---- Groups tab's fact lists: source_excerpt carried through ----------

    public function test_groups_tab_fact_lists_carry_source_excerpt(): void
    {
        $territory = Territory::create(['code' => '20', 'name' => 'Gipuzkoa 7g2', 'level' => 'provincial', 'aliases' => []]);
        $sector = Sector::create(['name' => 'Comercio 7g2b', 'aliases' => []]);
        $convenio = Convenio::create([
            'numero' => '20000125012099', 'name' => 'Comercio Gipuzkoa 7g2',
            'territory_id' => $territory->id, 'sector_id' => $sector->id,
        ]);
        $topic = Topic::firstOrCreate(['name' => 'periodo de prueba 7g2b'], ['status' => 'approved']);

        $node = ConvenioGroup::create([
            'convenio_id' => $convenio->id, 'label' => 'Grupo 1', 'code_normalized' => '1',
            'normalization_rule' => 'numeric_verbatim', 'status' => ConvenioGroup::STATUS_APPROVED, 'source' => 'admin_manual',
        ]);

        $excerpt = 'Grupo 1: periodo de prueba de sesenta días.';
        ReferenceFact::create([
            'convenio_id' => $convenio->id, 'topic_id' => $topic->id, 'group_label' => 'Grupo 1',
            'value' => '60 días', 'authority_level' => ReferenceFact::AUTHORITY_LEVEL,
            'source' => 'ai_agent', 'status' => 'needs_review', 'source_excerpt' => $excerpt,
        ]);

        // The tree payload: `would_bind_facts` on the node itself.
        $tree = $this->getJson("/admin/convenio-groups/convenio/{$convenio->id}", $this->auth())->assertStatus(200);
        $tree->assertJsonPath('tree.0.would_bind_facts.0.source_excerpt', $excerpt);

        // The binding-diff payload: `would_bind`.
        $diff = $this->getJson("/admin/convenio-groups/{$node->id}/binding-diff", $this->auth())->assertStatus(200);
        $diff->assertJsonPath('would_bind.0.source_excerpt', $excerpt);
    }
}
