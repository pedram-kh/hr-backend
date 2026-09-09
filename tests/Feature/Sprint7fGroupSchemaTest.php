<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Convenio;
use App\Models\ConvenioGroup;
use App\Models\ConvenioGroupCategory;
use App\Models\ConvenioJobCategory;
use App\Models\Employee;
use App\Models\ReferenceFact;
use App\Models\ReferenceFactGroupScope;
use App\Models\Sector;
use App\Models\Territory;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sprint 7f (ADR-0028) Phase 1 — the schema invariants, asserted against a real
 * Postgres rather than trusted.
 *
 * Every one of these is enforced in the DATABASE, not in application code, and
 * that distinction is the point: Phase 3's matcher is a single self-join that
 * assumes a grandparent cannot exist and that a convenio cannot hold two nodes
 * with the same code. If those can be violated by a bulk insert or a manual SQL
 * fix, the matcher is silently wrong rather than loudly broken.
 */
class Sprint7fGroupSchemaTest extends TestCase
{
    use RefreshDatabase;

    private Convenio $convenio;

    protected function setUp(): void
    {
        parent::setUp();

        $territory = Territory::create(['code' => '31', 'name' => 'Navarra', 'level' => 'provincial', 'aliases' => []]);
        $sector = Sector::create(['name' => 'Hostelería', 'aliases' => []]);
        $this->convenio = Convenio::create([
            'numero' => '31003805011981', 'name' => 'HOSTELERIA NAVARRA',
            'territory_id' => $territory->id, 'sector_id' => $sector->id,
        ]);
    }

    // ---- The tree ----------------------------------------------------------

    public function test_a_group_and_its_sub_areas_form_a_two_level_tree(): void
    {
        $g2 = $this->group('Grupo 2', '2');
        $area5 = $this->subArea($g2, 'área 5', 'area-5');
        $resto = $this->subArea($g2, 'resto áreas', 'resto-areas');

        $this->assertNull($g2->parent_id);
        $this->assertFalse($g2->isSubArea());
        $this->assertTrue($area5->isSubArea());
        $this->assertEqualsCanonicalizing(
            [$area5->id, $resto->id],
            $g2->children()->pluck('id')->all(),
        );
        $this->assertSame('Grupo 2 › resto áreas', $resto->fresh()->load('parent')->pathLabel());
        $this->assertSame('Grupo 2', $g2->pathLabel());
    }

    /**
     * Depth ≤ 2, enforced by the `convenio_groups_depth_check` trigger. A plain
     * CHECK cannot express this (the rule is about the parent's row, and Postgres
     * forbids subqueries in CHECK), which is why the trigger exists at all.
     */
    public function test_a_sub_area_of_a_sub_area_is_rejected_by_the_database(): void
    {
        $g2 = $this->group('Grupo 2', '2');
        $area5 = $this->subArea($g2, 'área 5', 'area-5');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/limited to two levels/');

        $this->subArea($area5, 'sub-sub', 'sub-sub');
    }

    public function test_promoting_a_group_that_already_has_children_under_another_node_is_rejected(): void
    {
        $g2 = $this->group('Grupo 2', '2');
        $this->subArea($g2, 'área 5', 'area-5');
        $g3 = $this->group('Grupo 3', '3');

        // Making G2 a child of G3 would give área 5 a grandparent WITHOUT ever
        // touching área 5's own row — so checking only "my parent must be a root"
        // misses it. The trigger looks down as well as up, and fires on
        // UPDATE OF parent_id, not only on insert.
        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/already has sub-areas of its own/');
        $g2->update(['parent_id' => $g3->id]);
    }

    public function test_a_sub_area_cannot_belong_to_a_group_in_another_convenio(): void
    {
        $other = Convenio::create([
            'numero' => '01003205012006', 'name' => 'ACTIVIDADES DEPORTIVAS',
            'territory_id' => $this->convenio->territory_id, 'sector_id' => $this->convenio->sector_id,
        ]);
        $foreignGroup = ConvenioGroup::create([
            'convenio_id' => $other->id, 'code_normalized' => '2', 'label' => 'Grupo 2',
            'status' => ConvenioGroup::STATUS_APPROVED, 'source' => ConvenioGroup::SOURCE_MANUAL,
        ]);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/belongs to convenio/');

        ConvenioGroup::create([
            'convenio_id' => $this->convenio->id, 'parent_id' => $foreignGroup->id,
            'code_normalized' => 'area-5', 'label' => 'área 5',
            'status' => ConvenioGroup::STATUS_APPROVED, 'source' => ConvenioGroup::SOURCE_MANUAL,
        ]);
    }

    // ---- Uniqueness --------------------------------------------------------

    /**
     * The case a single UNIQUE (convenio_id, parent_id, code_normalized) would
     * MISS: Postgres treats NULLs as distinct, so both rows would satisfy it and
     * the convenio would hold two "Grupo 2" nodes — exactly the ambiguity an
     * exact matcher must never face.
     */
    public function test_two_top_level_groups_cannot_share_a_code_in_one_convenio(): void
    {
        $this->group('Grupo 2', '2');

        $this->expectException(QueryException::class);
        $this->group('Grupo II', '2');
    }

    public function test_two_sub_areas_of_the_same_group_cannot_share_a_code(): void
    {
        $g2 = $this->group('Grupo 2', '2');
        $this->subArea($g2, 'resto áreas', 'resto-areas');

        $this->expectException(QueryException::class);
        $this->subArea($g2, 'Resto de áreas', 'resto-areas');
    }

    /** But the same sub-area code under DIFFERENT parents is legitimate. */
    public function test_the_same_sub_area_code_may_exist_under_two_different_groups(): void
    {
        $g1 = $this->group('Grupo 1', '1');
        $g3 = $this->group('Grupo 3', '3');

        $a = $this->subArea($g1, 'todas las áreas', 'todas-las-areas');
        $b = $this->subArea($g3, 'todas las áreas', 'todas-las-areas');

        $this->assertNotSame($a->id, $b->id);
        $this->assertSame(2, ConvenioGroup::where('code_normalized', 'todas-las-areas')->count());
    }

    /** And the same code in a DIFFERENT convenio is legitimate — every convenio has a Grupo 2. */
    public function test_the_same_group_code_may_exist_in_another_convenio(): void
    {
        $this->group('Grupo 2', '2');
        $other = Convenio::create([
            'numero' => '01003205012006', 'name' => 'ACTIVIDADES DEPORTIVAS',
            'territory_id' => $this->convenio->territory_id, 'sector_id' => $this->convenio->sector_id,
        ]);

        ConvenioGroup::create([
            'convenio_id' => $other->id, 'code_normalized' => '2', 'label' => 'Grupo 2',
            'status' => ConvenioGroup::STATUS_APPROVED, 'source' => ConvenioGroup::SOURCE_MANUAL,
        ]);

        $this->assertSame(2, ConvenioGroup::where('code_normalized', '2')->count());
    }

    // ---- Fact binding: many-to-many ---------------------------------------

    /**
     * The sprint's headline object, as schema. Staging's verified fact #44 reads
     * "Grupo 1 (todas las áreas) y Grupo 2 (área 5)" and spans two groups; the
     * spec's original single `(group_id, sub_area_id)` pair could not hold it.
     */
    public function test_one_compound_fact_binds_to_two_different_groups(): void
    {
        $g1 = $this->group('Grupo 1', '1');
        $g2 = $this->group('Grupo 2', '2');
        $area5 = $this->subArea($g2, 'área 5', 'area-5');

        $fact = $this->fact('Grupo 1 (todas las áreas) y Grupo 2 (área 5)', '90 días (indefinidos), 75, 60');
        $this->bind($fact, $g1);
        $this->bind($fact, $area5);

        $this->assertEqualsCanonicalizing(
            [$g1->id, $area5->id],
            $fact->groupScopes()->pluck('convenio_groups.id')->all(),
        );

        // The two sibling facts bind to one node each — three facts, four bindings.
        $resto = $this->subArea($g2, 'resto áreas', 'resto-areas');
        $g3 = $this->group('Grupo 3', '3');
        $this->bind($this->fact('Grupo 2 (resto áreas)', '60/45/30'), $resto);
        $this->bind($this->fact('Grupo 3 (todas las áreas)', '45/30/15'), $g3);

        $this->assertSame(4, ReferenceFactGroupScope::count());
    }

    public function test_a_fact_cannot_bind_to_the_same_node_twice(): void
    {
        $g1 = $this->group('Grupo 1', '1');
        $fact = $this->fact('Grupo 1', '5 meses');
        $this->bind($fact, $g1);

        $this->expectException(QueryException::class);
        $this->bind($fact, $g1);
    }

    /** `reference_facts` gained no columns, and `group_label` is untouched. */
    public function test_reference_facts_gains_no_columns_and_group_label_survives_binding(): void
    {
        $g1 = $this->group('Grupo 1', '1');
        $fact = $this->fact('Grupo 1 (todas las áreas) y Grupo 2 (área 5)', '90/75/60');
        $this->bind($fact, $g1);

        $this->assertFalse(\Schema::hasColumn('reference_facts', 'group_id'));
        $this->assertFalse(\Schema::hasColumn('reference_facts', 'sub_area_id'));
        $this->assertFalse(\Schema::hasColumn('reference_facts', 'convenio_group_id'));
        $this->assertSame('Grupo 1 (todas las áreas) y Grupo 2 (área 5)', $fact->fresh()->group_label);
    }

    /** Deleting a fact takes its bindings with it and leaves the group standing. */
    public function test_deleting_a_fact_cascades_its_bindings_but_not_the_group(): void
    {
        $g1 = $this->group('Grupo 1', '1');
        $fact = $this->fact('Grupo 1', '5 meses');
        $this->bind($fact, $g1);

        $fact->delete();

        $this->assertSame(0, ReferenceFactGroupScope::count());
        $this->assertNotNull($g1->fresh());
    }

    /** A sub-area cannot outlive the group it slices. */
    public function test_deleting_a_group_cascades_its_sub_areas_and_their_bindings(): void
    {
        $g2 = $this->group('Grupo 2', '2');
        $resto = $this->subArea($g2, 'resto áreas', 'resto-areas');
        $this->bind($this->fact('Grupo 2 (resto áreas)', '60/45/30'), $resto);

        $g2->delete();

        $this->assertNull(ConvenioGroup::find($resto->id));
        $this->assertSame(0, ReferenceFactGroupScope::count());
    }

    // ---- Category membership ----------------------------------------------

    /**
     * One APPROVED membership per category, so the directory's suggested default
     * is never ambiguous — but competing PROPOSALS may coexist for a human to
     * choose between, which a flat unique index would have made un-insertable.
     */
    public function test_a_category_may_have_competing_proposals_but_only_one_approved_membership(): void
    {
        $g1 = $this->group('Grupo 1', '1');
        $g2 = $this->group('Grupo 2', '2');
        $category = ConvenioJobCategory::create([
            'convenio_id' => $this->convenio->id, 'name' => 'Jefe de cocina',
        ]);

        ConvenioGroupCategory::create([
            'convenio_group_id' => $g1->id, 'job_category_id' => $category->id,
            'status' => ConvenioGroup::STATUS_NEEDS_REVIEW, 'source' => ConvenioGroup::SOURCE_AI,
        ]);
        ConvenioGroupCategory::create([
            'convenio_group_id' => $g2->id, 'job_category_id' => $category->id,
            'status' => ConvenioGroup::STATUS_NEEDS_REVIEW, 'source' => ConvenioGroup::SOURCE_AI,
        ]);
        $this->assertSame(2, ConvenioGroupCategory::where('job_category_id', $category->id)->count());

        ConvenioGroupCategory::where('convenio_group_id', $g1->id)
            ->update(['status' => ConvenioGroup::STATUS_APPROVED]);

        $this->expectException(QueryException::class);
        ConvenioGroupCategory::where('convenio_group_id', $g2->id)
            ->update(['status' => ConvenioGroup::STATUS_APPROVED]);
    }

    // ---- The employee column ----------------------------------------------

    public function test_employees_convenio_group_id_defaults_to_null_and_is_nullable(): void
    {
        $employee = Employee::create([
            'email' => 'no-group@example.com', 'full_name' => 'Sin grupo',
            'convenio_id' => $this->convenio->id, 'territory_id' => $this->convenio->territory_id,
            'employment_type' => 'full_time', 'status' => 'active',
        ]);

        // Unresolved is the default for every employee this migration touches.
        $this->assertNull($employee->fresh()->convenio_group_id);
        $this->assertNull($employee->convenioGroup);
    }

    public function test_an_employee_can_point_at_a_sub_area_and_reads_it_back(): void
    {
        $g2 = $this->group('Grupo 2', '2');
        $resto = $this->subArea($g2, 'resto áreas', 'resto-areas');

        $employee = Employee::create([
            'email' => 'resto@example.com', 'full_name' => 'Resto áreas',
            'convenio_id' => $this->convenio->id, 'convenio_group_id' => $resto->id,
            'territory_id' => $this->convenio->territory_id,
            'employment_type' => 'full_time', 'status' => 'active',
        ]);

        $group = $employee->fresh()->convenioGroup;
        $this->assertSame('resto-areas', $group->code_normalized);
        $this->assertSame($g2->id, $group->parent_id);
    }

    /** Deleting a node must not delete the employee — it leaves them unresolved. */
    public function test_deleting_a_group_leaves_the_employee_unresolved_rather_than_deleted(): void
    {
        $g2 = $this->group('Grupo 2', '2');
        $employee = Employee::create([
            'email' => 'g2@example.com', 'full_name' => 'Grupo 2',
            'convenio_id' => $this->convenio->id, 'convenio_group_id' => $g2->id,
            'territory_id' => $this->convenio->territory_id,
            'employment_type' => 'full_time', 'status' => 'active',
        ]);

        $g2->delete();

        $this->assertNotNull($employee->fresh());
        $this->assertNull($employee->fresh()->convenio_group_id);
    }

    // ---- helpers -----------------------------------------------------------

    private function group(string $label, string $code): ConvenioGroup
    {
        return ConvenioGroup::create([
            'convenio_id' => $this->convenio->id, 'code_normalized' => $code, 'label' => $label,
            'status' => ConvenioGroup::STATUS_APPROVED, 'source' => ConvenioGroup::SOURCE_MANUAL,
        ]);
    }

    private function subArea(ConvenioGroup $parent, string $label, string $code): ConvenioGroup
    {
        return ConvenioGroup::create([
            'convenio_id' => $this->convenio->id, 'parent_id' => $parent->id,
            'code_normalized' => $code, 'label' => $label,
            'source_excerpt' => 'Se establecen las áreas … (excerpt required for a sub-area)',
            'status' => ConvenioGroup::STATUS_APPROVED, 'source' => ConvenioGroup::SOURCE_MANUAL,
        ]);
    }

    private function fact(string $groupLabel, string $value): ReferenceFact
    {
        return ReferenceFact::create([
            'convenio_id' => $this->convenio->id, 'group_label' => $groupLabel,
            'value' => $value, 'status' => 'verified', 'source' => 'ai_agent',
            'validity_start' => '2026-01-01',
        ]);
    }

    private function bind(ReferenceFact $fact, ConvenioGroup $group): ReferenceFactGroupScope
    {
        return ReferenceFactGroupScope::create([
            'reference_fact_id' => $fact->id, 'convenio_group_id' => $group->id,
            'bound_by' => $this->admin()->id, 'bound_at' => now(),
        ]);
    }

    private function admin(): Admin
    {
        return Admin::firstOrCreate(
            ['email' => 'admin-7f@example.com'],
            ['full_name' => 'Admin 7f', 'password' => bcrypt('secret')],
        );
    }
}
