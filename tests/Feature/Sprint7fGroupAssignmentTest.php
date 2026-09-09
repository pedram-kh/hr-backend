<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Convenio;
use App\Models\ConvenioGroup;
use App\Models\ConvenioGroupCategory;
use App\Models\ConvenioJobCategory;
use App\Models\Employee;
use App\Models\Sector;
use App\Models\Territory;
use App\Services\EmployeeCsvImporter;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Sprint 7f (ADR-0028) Phase 1 — how a group gets ONTO an employee, proven
 * server-side against the real endpoints.
 *
 * The rule that every test here defends: A GROUP IS NEVER ASSIGNED WITHOUT AN
 * ADMIN SAYING SO, AND NEVER TO UNAPPROVED STRUCTURE. Category membership may
 * SUGGEST a node; it may not set one. An ambiguous CSV value fails its row
 * rather than picking. Blank stays blank, which is what makes a group-scoped
 * question escalate instead of guess.
 *
 * The convenio modelled here is Hostelería Navarra: Grupo 2 split into `área 5`
 * and `resto áreas`, which the convenio prices differently.
 */
class Sprint7fGroupAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private Territory $territory;

    private Convenio $convenio;

    private ConvenioGroup $g1;

    private ConvenioGroup $g2;

    private ConvenioGroup $area5;

    private ConvenioGroup $resto;

    private ConvenioGroup $pending;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        $this->territory = Territory::create(['code' => '31', 'name' => 'Navarra', 'level' => 'provincial', 'aliases' => []]);
        $sector = Sector::create(['name' => 'Hostelería', 'aliases' => []]);
        $this->convenio = Convenio::create([
            'numero' => '31003805011981', 'name' => 'HOSTELERIA NAVARRA',
            'territory_id' => $this->territory->id, 'sector_id' => $sector->id,
        ]);

        $this->g1 = $this->node('Grupo 1', '1');
        $this->g2 = $this->node('Grupo 2', '2');
        $this->area5 = $this->node('área 5', 'area-5', $this->g2);
        $this->resto = $this->node('resto áreas', 'resto-areas', $this->g2);

        // An AI proposal nobody has approved. It must be invisible everywhere.
        $this->pending = $this->node('Grupo 3', '3', null, ConvenioGroup::STATUS_NEEDS_REVIEW);
    }

    // ---- The picker endpoint ----------------------------------------------

    public function test_the_picker_returns_the_approved_tree_parent_first_with_depth(): void
    {
        $response = $this->getAs($this->admin(), '/admin/groups?convenio_id='.$this->convenio->id);
        $response->assertStatus(200);

        $items = $response->json('items');

        // Grupo 1, then Grupo 2 with its two sub-areas nested immediately after.
        $this->assertSame(
            ['Grupo 1', 'Grupo 2', 'área 5', 'resto áreas'],
            array_column($items, 'label'),
        );
        $this->assertSame([0, 0, 1, 1], array_column($items, 'depth'));
        $this->assertSame('Grupo 2 › resto áreas', $items[3]['path_label']);
        $this->assertSame($this->g2->id, $items[3]['parent_id']);
    }

    /** A proposal is inert here too — offering it would route around the human gate. */
    public function test_the_picker_never_offers_an_unapproved_node(): void
    {
        $items = $this->getAs($this->admin(), '/admin/groups?convenio_id='.$this->convenio->id)->json('items');

        $this->assertNotContains('Grupo 3', array_column($items, 'label'));
        $this->assertNotContains($this->pending->id, array_column($items, 'id'));
    }

    public function test_the_picker_is_scoped_to_one_convenio(): void
    {
        $other = Convenio::create([
            'numero' => '01003205012006', 'name' => 'ACTIVIDADES DEPORTIVAS',
            'territory_id' => $this->territory->id, 'sector_id' => $this->convenio->sector_id,
        ]);
        ConvenioGroup::create([
            'convenio_id' => $other->id, 'code_normalized' => '9', 'label' => 'Grupo 9',
            'status' => ConvenioGroup::STATUS_APPROVED, 'source' => ConvenioGroup::SOURCE_MANUAL,
        ]);

        $items = $this->getAs($this->admin(), '/admin/groups?convenio_id='.$this->convenio->id)->json('items');
        $this->assertNotContains('Grupo 9', array_column($items, 'label'));
    }

    /**
     * The convenio-21 reality: no categories at all, so a category can suggest
     * nothing — and the picker still works, because an employee binds to a group
     * directly. This is why groups are their own vocabulary.
     */
    public function test_the_picker_works_for_a_convenio_with_no_job_categories(): void
    {
        $this->assertSame(0, $this->convenio->jobCategories()->count());

        $response = $this->getAs($this->admin(), '/admin/groups?convenio_id='.$this->convenio->id);
        $this->assertCount(4, $response->json('items'));
        $this->assertNull($response->json('suggested_group_id'));
    }

    // ---- The suggestion (a default, never an assignment) -------------------

    public function test_an_approved_membership_suggests_a_node_but_writes_nothing(): void
    {
        $category = ConvenioJobCategory::create(['convenio_id' => $this->convenio->id, 'name' => 'Camarero/a']);
        ConvenioGroupCategory::create([
            'convenio_group_id' => $this->resto->id, 'job_category_id' => $category->id,
            'status' => ConvenioGroup::STATUS_APPROVED, 'source' => ConvenioGroup::SOURCE_MANUAL,
            'approved_by' => $this->admin()->id, 'approved_at' => now(),
        ]);

        $employee = $this->employee(['job_category_id' => $category->id]);

        $response = $this->getAs($this->admin(),
            '/admin/groups?convenio_id='.$this->convenio->id.'&job_category_id='.$category->id);

        $this->assertSame($this->resto->id, $response->json('suggested_group_id'));
        // Suggested, NOT assigned. The employee is still unresolved until a save.
        $this->assertNull($employee->fresh()->convenio_group_id);
    }

    /** An unapproved membership suggests nothing. */
    public function test_a_proposed_membership_does_not_suggest_a_node(): void
    {
        $category = ConvenioJobCategory::create(['convenio_id' => $this->convenio->id, 'name' => 'Camarero/a']);
        ConvenioGroupCategory::create([
            'convenio_group_id' => $this->resto->id, 'job_category_id' => $category->id,
            'status' => ConvenioGroup::STATUS_NEEDS_REVIEW, 'source' => ConvenioGroup::SOURCE_AI,
        ]);

        $response = $this->getAs($this->admin(),
            '/admin/groups?convenio_id='.$this->convenio->id.'&job_category_id='.$category->id);

        $this->assertNull($response->json('suggested_group_id'));
    }

    // ---- Directory write validation ---------------------------------------

    public function test_an_admin_can_assign_an_approved_sub_area_and_it_reads_back(): void
    {
        $employee = $this->employee();

        $response = $this->patchAs($this->admin(), '/admin/employees/'.$employee->uuid, [
            'email' => $employee->email, 'full_name' => $employee->full_name,
            'convenio_id' => $this->convenio->id, 'territory_id' => $this->territory->id,
            'convenio_group_id' => $this->resto->id, 'employment_type' => 'full_time',
        ]);
        $response->assertStatus(200);

        $this->assertSame($this->resto->id, $employee->fresh()->convenio_group_id);
        $this->assertSame('Grupo 2 › resto áreas', $response->json('employee.convenio_group.path_label'));
        $this->assertTrue($response->json('employee.convenio_group.is_sub_area'));
    }

    public function test_assigning_an_unapproved_node_is_rejected_422(): void
    {
        $employee = $this->employee();

        $this->patchAs($this->admin(), '/admin/employees/'.$employee->uuid, [
            'email' => $employee->email, 'full_name' => $employee->full_name,
            'convenio_id' => $this->convenio->id, 'territory_id' => $this->territory->id,
            'convenio_group_id' => $this->pending->id, 'employment_type' => 'full_time',
        ])->assertStatus(422)->assertJsonPath('errors.convenio_group_id.0',
            'El grupo debe estar aprobado antes de asignarlo.');

        $this->assertNull($employee->fresh()->convenio_group_id);
    }

    public function test_assigning_a_node_from_another_convenio_is_rejected_422(): void
    {
        $other = Convenio::create([
            'numero' => '01003205012006', 'name' => 'ACTIVIDADES DEPORTIVAS',
            'territory_id' => $this->territory->id, 'sector_id' => $this->convenio->sector_id,
        ]);
        $foreign = ConvenioGroup::create([
            'convenio_id' => $other->id, 'code_normalized' => '1', 'label' => 'Grupo 1',
            'status' => ConvenioGroup::STATUS_APPROVED, 'source' => ConvenioGroup::SOURCE_MANUAL,
        ]);
        $employee = $this->employee();

        $this->patchAs($this->admin(), '/admin/employees/'.$employee->uuid, [
            'email' => $employee->email, 'full_name' => $employee->full_name,
            'convenio_id' => $this->convenio->id, 'territory_id' => $this->territory->id,
            'convenio_group_id' => $foreign->id, 'employment_type' => 'full_time',
        ])->assertStatus(422)->assertJsonPath('errors.convenio_group_id.0',
            'El grupo no pertenece al convenio.');
    }

    /** Omitting the field clears it — the same "blank means unresolved" default. */
    public function test_saving_without_a_group_leaves_the_employee_unresolved(): void
    {
        $employee = $this->employee(['convenio_group_id' => $this->g1->id]);

        $this->patchAs($this->admin(), '/admin/employees/'.$employee->uuid, [
            'email' => $employee->email, 'full_name' => $employee->full_name,
            'convenio_id' => $this->convenio->id, 'territory_id' => $this->territory->id,
            'employment_type' => 'full_time',
        ])->assertStatus(200);

        $this->assertNull($employee->fresh()->convenio_group_id);
    }

    // ---- The CSV `group` column -------------------------------------------

    public function test_csv_resolves_a_top_level_group_by_label_and_by_code(): void
    {
        foreach (['Grupo 1', '1', 'Grupo I'] as $written) {
            $email = 'g'.md5($written).'@example.com';
            $report = $this->importer()->apply($this->csv($written, $email), $this->admin());

            $this->assertSame('pass', $report['rows'][0]['status'], "failed for \"{$written}\"");
            $this->assertSame($this->g1->id, Employee::where('email', $email)->value('convenio_group_id'),
                "wrong node for \"{$written}\"");
        }
    }

    /**
     * `Grupo I` and `Grupo 1` must address the SAME node — the roman→arabic rule
     * exists because the two source fixtures spell it both ways.
     */
    public function test_csv_treats_roman_and_arabic_as_the_same_node(): void
    {
        $this->importer()->apply($this->csv('Grupo I', 'roman@example.com'), $this->admin());
        $this->importer()->apply($this->csv('Grupo 1', 'arabic@example.com'), $this->admin());

        $this->assertSame(
            Employee::where('email', 'roman@example.com')->value('convenio_group_id'),
            Employee::where('email', 'arabic@example.com')->value('convenio_group_id'),
        );
    }

    public function test_csv_resolves_a_sub_area_with_the_explicit_separator(): void
    {
        $report = $this->importer()->apply($this->csv('Grupo 2 > resto áreas'), $this->admin());

        $this->assertSame('pass', $report['rows'][0]['status']);
        $this->assertSame($this->resto->id, Employee::where('email', 'csv@example.com')->value('convenio_group_id'));
    }

    public function test_csv_resolves_a_sub_area_written_entirely_in_normalized_codes(): void
    {
        $report = $this->importer()->apply($this->csv('2 > area-5'), $this->admin());

        $this->assertSame('pass', $report['rows'][0]['status']);
        $this->assertSame($this->area5->id, Employee::where('email', 'csv@example.com')->value('convenio_group_id'));
    }

    /** A bare sub-area name is fine while it is unambiguous within the convenio. */
    public function test_csv_resolves_an_unambiguous_bare_sub_area_name(): void
    {
        $report = $this->importer()->apply($this->csv('resto áreas'), $this->admin());

        $this->assertSame('pass', $report['rows'][0]['status']);
        $this->assertSame($this->resto->id, Employee::where('email', 'csv@example.com')->value('convenio_group_id'));
    }

    /**
     * AMBIGUITY FAILS THE ROW. `todas las áreas` under both Grupo 1 and Grupo 3 is
     * the real shape of the Hostelería facts, and picking the first match would be
     * the same class of silent guess the digit matcher made.
     */
    public function test_csv_fails_the_row_when_a_bare_name_is_ambiguous_and_names_the_options(): void
    {
        $g3 = $this->node('Grupo 3', '3-real');
        $this->node('todas las áreas', 'todas-las-areas', $this->g1);
        $this->node('todas las áreas', 'todas-las-areas', $g3);

        $report = $this->importer()->validate($this->csv('todas las áreas'));
        $row = $report['rows'][0];

        $this->assertSame('fail', $row['status']);
        $this->assertSame('skip', $row['action']);
        $this->assertStringContainsString('ambiguo', $row['errors'][0]);
        $this->assertStringContainsString('Grupo 1 › todas las áreas', $row['errors'][0]);
        $this->assertStringContainsString('Grupo 3 › todas las áreas', $row['errors'][0]);
    }

    public function test_csv_fails_the_row_for_an_unknown_group_and_never_mints_one(): void
    {
        $before = ConvenioGroup::count();
        $report = $this->importer()->validate($this->csv('Grupo 47'));

        $this->assertSame('fail', $report['rows'][0]['status']);
        $this->assertStringContainsString('no existe en el convenio', $report['rows'][0]['errors'][0]);
        $this->assertSame($before, ConvenioGroup::count());
    }

    public function test_csv_fails_the_row_for_an_unapproved_group(): void
    {
        $report = $this->importer()->validate($this->csv('Grupo 3'));

        $this->assertSame('fail', $report['rows'][0]['status']);
        $this->assertStringContainsString('no está aprobado', $report['rows'][0]['errors'][0]);
    }

    public function test_csv_rejects_more_than_two_levels(): void
    {
        $report = $this->importer()->validate($this->csv('Grupo 2 > resto áreas > otra cosa'));

        $this->assertSame('fail', $report['rows'][0]['status']);
        $this->assertStringContainsString('solo hay dos niveles', $report['rows'][0]['errors'][0]);
    }

    /** A blank column is not an error — unresolved is the normal state. */
    public function test_csv_leaves_the_group_null_when_the_column_is_blank(): void
    {
        $report = $this->importer()->apply($this->csv(''), $this->admin());

        $this->assertSame('pass', $report['rows'][0]['status']);
        $this->assertNull(Employee::where('email', 'csv@example.com')->value('convenio_group_id'));
    }

    /** And a file with no `group` column at all still imports, unchanged from Sprint 5. */
    public function test_csv_without_the_group_column_still_imports(): void
    {
        $report = $this->importer()->apply([
            ['email', 'full_name', 'convenio_numero'],
            ['nocolumn@example.com', 'Sin Columna', $this->convenio->numero],
        ], $this->admin());

        $this->assertSame('pass', $report['rows'][0]['status']);
        $this->assertNull(Employee::where('email', 'nocolumn@example.com')->value('convenio_group_id'));
    }

    public function test_csv_apply_writes_the_resolved_group(): void
    {
        $report = $this->importer()->apply($this->csv('Grupo 2 > área 5'), $this->admin());

        $this->assertSame(1, $report['summary']['created']);
        $this->assertSame($this->area5->id, Employee::where('email', 'csv@example.com')->value('convenio_group_id'));
    }

    // ---- helpers -----------------------------------------------------------

    private function node(string $label, string $code, ?ConvenioGroup $parent = null, string $status = ConvenioGroup::STATUS_APPROVED): ConvenioGroup
    {
        return ConvenioGroup::create([
            'convenio_id' => $this->convenio->id,
            'parent_id' => $parent?->id,
            'code_normalized' => $code,
            'label' => $label,
            'source_excerpt' => $parent !== null ? 'Las áreas se definen en el art. 12.' : null,
            'status' => $status,
            'source' => ConvenioGroup::SOURCE_MANUAL,
        ]);
    }

    /** @param array<string,mixed> $attributes */
    private function employee(array $attributes = []): Employee
    {
        return Employee::create(array_merge([
            'email' => 'worker@example.com', 'full_name' => 'Trabajador',
            'convenio_id' => $this->convenio->id, 'territory_id' => $this->territory->id,
            'employment_type' => 'full_time', 'status' => 'active',
        ], $attributes));
    }

    /** @return array<int, array<int, string>> */
    private function csv(string $group, string $email = 'csv@example.com'): array
    {
        return [
            ['email', 'full_name', 'convenio_numero', 'group'],
            [$email, 'Trabajador CSV', $this->convenio->numero, $group],
        ];
    }

    private function importer(): EmployeeCsvImporter
    {
        return app(EmployeeCsvImporter::class);
    }

    private function admin(): Admin
    {
        static $admin = null;
        if ($admin === null || $admin->fresh() === null) {
            $admin = Admin::create([
                'email' => 'super-7f@example.com', 'full_name' => 'Super 7f', 'status' => 'active',
            ]);
            $admin->assignRole('super_admin');
        }

        return $admin;
    }

    /** @return array<string,string> */
    private function auth(Admin $admin): array
    {
        return ['Authorization' => 'Bearer '.$admin->createToken('test')->plainTextToken, 'Accept' => 'application/json'];
    }

    private function resetPermCache(): void
    {
        $this->app['auth']->forgetGuards();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function getAs(Admin $admin, string $url): TestResponse
    {
        $this->resetPermCache();

        return $this->getJson($url, $this->auth($admin));
    }

    /** @param array<string,mixed> $payload */
    private function patchAs(Admin $admin, string $url, array $payload): TestResponse
    {
        $this->resetPermCache();

        return $this->patchJson($url, $payload, $this->auth($admin));
    }
}
