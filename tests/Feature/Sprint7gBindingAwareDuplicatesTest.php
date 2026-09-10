<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Convenio;
use App\Models\ConvenioGroup;
use App\Models\ReferenceFact;
use App\Models\ReferenceFactGroupScope;
use App\Models\Sector;
use App\Models\TagEvent;
use App\Models\Territory;
use App\Models\Topic;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sprint 7g Item 4 (F-2) — `facts:scan-duplicates` becomes binding-aware.
 *
 * The false positives this fixes: two facts bound to SIBLING sub-area nodes of
 * a split group (e.g. "área 5" / "resto de áreas" of the SAME "Grupo 2") share
 * the "Grupo 2" digit token, so the pre-7g token-overlap pass flagged them as a
 * version pair even though they are genuinely different, coexisting scopes.
 * Once BOTH facts are bound (Sprint 7f), binding is authoritative: compare the
 * NODES, not the label text.
 *
 * The true positive that must still flag: the documented Navarra Acción e
 * Intervención Social re-group ("Grupos 1 y 2" → "Grupo 2") — reproduced here
 * bound to nodes (ancestor/descendant across a re-split), proving the fix adds
 * precision without losing the original catch.
 */
class Sprint7gBindingAwareDuplicatesTest extends TestCase
{
    use RefreshDatabase;

    private Convenio $convenio;

    private Topic $topic;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        $territory = Territory::create(['code' => '31', 'name' => 'Navarra', 'level' => 'provincial', 'aliases' => []]);
        $sector = Sector::create(['name' => 'Acción e Intervención Social', 'aliases' => []]);
        $this->convenio = Convenio::create([
            'numero' => '31101815012021', 'name' => 'Navarra Acción e Intervención Social',
            'territory_id' => $territory->id, 'sector_id' => $sector->id,
        ]);
        $this->topic = Topic::firstOrCreate(['name' => 'periodo de prueba'], ['status' => 'approved']);
    }

    private function node(string $label, string $code, ?ConvenioGroup $parent = null): ConvenioGroup
    {
        return ConvenioGroup::create([
            'convenio_id' => $this->convenio->id,
            'parent_id' => $parent?->id,
            'label' => $label,
            'code_normalized' => $code,
            'status' => ConvenioGroup::STATUS_APPROVED,
            'source' => 'admin_manual',
        ]);
    }

    private function fact(string $group, string $value, ?string $validityStart, string $status = 'verified'): ReferenceFact
    {
        return ReferenceFact::create([
            'convenio_id' => $this->convenio->id,
            'topic_id' => $this->topic->id,
            'group_label' => $group,
            'value' => $value,
            'validity_start' => $validityStart,
            'authority_level' => ReferenceFact::AUTHORITY_LEVEL,
            'source' => 'ai_agent',
            'status' => $status,
        ]);
    }

    private function bind(ReferenceFact $fact, ConvenioGroup $node): void
    {
        ReferenceFactGroupScope::create([
            'reference_fact_id' => $fact->id,
            'convenio_group_id' => $node->id,
            'bound_at' => now(),
        ]);
    }

    // ---- the false-positive fix: bound SIBLINGS are never flagged -----------

    public function test_bound_sibling_subareas_are_not_flagged_even_though_labels_token_overlap(): void
    {
        $grupo2 = $this->node('Grupo 2', 'grupo-2');
        $area5 = $this->node('área 5', 'area-5', $grupo2);
        $resto = $this->node('resto de áreas', 'resto', $grupo2);

        // Labels both carry the "2" token from "Grupo 2" — the pre-7g token
        // pass would flag this pair (GroupLabel::relate would see overlap).
        $fact44 = $this->fact('Grupo 2 (área 5)', '45 días', '2024-01-01');
        $this->bind($fact44, $area5);
        $fact45 = $this->fact('Grupo 2 (resto de áreas)', '30 días', '2024-01-01');
        $this->bind($fact45, $resto);

        $this->artisan('facts:scan-duplicates')->assertExitCode(0);

        $fact44->refresh();
        $fact45->refresh();
        $this->assertNull($fact44->duplicate_of_id, 'sibling sub-areas of a split group are NOT a version pair');
        $this->assertNull($fact45->duplicate_of_id);
        $this->assertSame(0, TagEvent::where('facet', 'duplicate')->count());
    }

    public function test_dry_run_reports_zero_pairs_for_the_sibling_case(): void
    {
        $grupo2 = $this->node('Grupo 2', 'grupo-2');
        $area5 = $this->node('área 5', 'area-5', $grupo2);
        $resto = $this->node('resto de áreas', 'resto', $grupo2);
        $this->bind($this->fact('Grupo 2 (área 5)', '45 días', '2024-01-01'), $area5);
        $this->bind($this->fact('Grupo 2 (resto de áreas)', '30 días', '2024-01-01'), $resto);

        \Illuminate\Support\Facades\Artisan::call('facts:scan-duplicates', ['--dry-run' => true]);
        $output = \Illuminate\Support\Facades\Artisan::output();
        $this->assertStringContainsString('no overlapping-group version pairs found', $output);
    }

    // ---- true positives still flag, now via bound nodes ----------------------

    public function test_ancestor_and_descendant_bound_nodes_are_flagged_as_a_version_candidate(): void
    {
        $grupo2 = $this->node('Grupo 2', 'grupo-2');
        $area5 = $this->node('área 5', 'area-5', $grupo2);

        $older = $this->fact('Grupo 2', 'Seis meses', '2021-01-01');
        $this->bind($older, $grupo2);
        $newer = $this->fact('Grupo 2 (área 5)', 'Cuatro meses', '2026-01-01');
        $this->bind($newer, $area5);

        $this->artisan('facts:scan-duplicates')->assertExitCode(0);

        $newer->refresh();
        $this->assertSame($older->id, $newer->duplicate_of_id, 'a bound sub-area vs its own parent group must flag as a candidate re-split');
        $this->assertSame('version', $newer->uncertainty['field']);

        $event = TagEvent::where('entity_type', 'reference_fact')->where('facet', 'duplicate')->sole();
        $this->assertSame('ai_agent', $event->source);
    }

    public function test_the_same_bound_node_is_flagged(): void
    {
        $grupo1 = $this->node('Grupo 1', 'grupo-1');
        $older = $this->fact('Grupo 1', '90 días', '2021-01-01');
        $this->bind($older, $grupo1);
        $newer = $this->fact('Grupo 1', '75 días', '2026-01-01');
        $this->bind($newer, $grupo1);

        $this->artisan('facts:scan-duplicates')->assertExitCode(0);

        $newer->refresh();
        $this->assertSame($older->id, $newer->duplicate_of_id);
    }

    public function test_two_unrelated_top_level_groups_are_not_flagged(): void
    {
        $grupo1 = $this->node('Grupo 1', 'grupo-1');
        $grupo2 = $this->node('Grupo 2', 'grupo-2');
        $f1 = $this->fact('Grupo 1', '90 días', '2024-01-01');
        $this->bind($f1, $grupo1);
        $f2 = $this->fact('Grupo 2', '75 días', '2024-01-01');
        $this->bind($f2, $grupo2);

        $this->artisan('facts:scan-duplicates')->assertExitCode(0);

        $this->assertNull($f1->refresh()->duplicate_of_id);
        $this->assertNull($f2->refresh()->duplicate_of_id);
    }

    // ---- mixed bound/unbound falls back to the token pass -------------------

    public function test_an_unbound_fact_against_a_bound_one_still_falls_back_to_the_token_pass(): void
    {
        $grupo2 = $this->node('Grupo 2', 'grupo-2');
        $older = $this->fact('Grupos 1 y 2', 'Seis meses', '2021-01-01'); // UNBOUND
        $newer = $this->fact('Grupo 2', 'Cuatro meses', '2026-01-01');
        $this->bind($newer, $grupo2); // bound

        $this->artisan('facts:scan-duplicates')->assertExitCode(0);

        $newer->refresh();
        $this->assertSame($older->id, $newer->duplicate_of_id, 'one fact unbound => the pre-7g token pass still applies, unchanged');
    }

    public function test_both_unbound_still_uses_the_pre_7g_token_pass_unchanged(): void
    {
        $older = $this->fact('Grupos 1 y 2', 'Seis meses', '2021-01-01');
        $newer = $this->fact('Grupo 2', 'Cuatro meses', '2026-01-01');

        $this->artisan('facts:scan-duplicates')->assertExitCode(0);

        $newer->refresh();
        $this->assertSame($older->id, $newer->duplicate_of_id);
    }
}
