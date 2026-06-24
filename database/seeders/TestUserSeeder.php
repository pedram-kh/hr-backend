<?php

namespace Database\Seeders;

use App\Models\Admin;
use App\Models\Convenio;
use App\Models\ConvenioJobCategory;
use App\Models\Employee;
use App\Models\Sector;
use App\Models\Territory;
use Illuminate\Database\Seeder;

/**
 * One test admin + one test employee (real emails the tester controls, from .env).
 *
 * Review C6 (confirms Q3): employees.convenio_id / territory_id are NOT NULL, but
 * the real convenio registry is Sprint 1. So we seed a single sector/convenio/
 * job-category explicitly labelled `DEV FIXTURE — placeholder` purely to satisfy
 * the test employee's FKs. This is a dev fixture, NOT the real registry.
 */
class TestUserSeeder extends Seeder
{
    private const FIXTURE_LABEL = 'DEV FIXTURE — placeholder';

    public function run(): void
    {
        $adminEmail = strtolower((string) env('SEED_ADMIN_EMAIL', 'admin@example.com'));
        $employeeEmail = strtolower((string) env('SEED_EMPLOYEE_EMAIL', 'employee@example.com'));

        // --- Dev fixture vocabulary (clearly labelled) ---
        $territory = Territory::where('code', '01')->firstOrFail(); // Álava (seeded)

        $sector = Sector::firstOrCreate(
            ['name' => self::FIXTURE_LABEL],
            ['aliases' => []],
        );

        $convenio = Convenio::firstOrCreate(
            ['numero' => 'DEV-FIXTURE-0001'],
            [
                'name' => self::FIXTURE_LABEL,
                'territory_id' => $territory->id,
                'sector_id' => $sector->id,
                'notes' => 'Dev fixture only — replaced by the real registry import in Sprint 1.',
            ],
        );

        $jobCategory = ConvenioJobCategory::firstOrCreate(
            ['convenio_id' => $convenio->id, 'name' => self::FIXTURE_LABEL],
            ['group_code' => null],
        );

        // --- Test admin (super_admin) ---
        $admin = Admin::updateOrCreate(
            ['email' => $adminEmail],
            ['full_name' => 'Test Admin', 'status' => 'active'],
        );
        if (! $admin->hasRole('super_admin')) {
            $admin->assignRole('super_admin');
        }

        // --- Sprint 3: a knowledge_editor and a read-only auditor (env-driven), so
        // the Knowledge-Center role gating is testable eyes-on (can-edit vs not).
        $editorEmail = strtolower((string) env('SEED_EDITOR_EMAIL', 'editor@example.com'));
        $editor = Admin::updateOrCreate(
            ['email' => $editorEmail],
            ['full_name' => 'Test Knowledge Editor', 'status' => 'active'],
        );
        if (! $editor->hasRole('knowledge_editor')) {
            $editor->assignRole('knowledge_editor');
        }

        $auditorEmail = strtolower((string) env('SEED_AUDITOR_EMAIL', 'auditor@example.com'));
        $auditor = Admin::updateOrCreate(
            ['email' => $auditorEmail],
            ['full_name' => 'Test Auditor', 'status' => 'active'],
        );
        if (! $auditor->hasRole('auditor')) {
            $auditor->assignRole('auditor');
        }

        // --- Sprint 4: an hr_agent (the intended escalation-board worker), so the
        // board's assign/move/reply/resolve + the escalation.work gate are
        // testable eyes-on. Full directory/role management is still Sprint 5.
        $hrAgentEmail = strtolower((string) env('SEED_HR_AGENT_EMAIL', 'agent@example.com'));
        $hrAgent = Admin::updateOrCreate(
            ['email' => $hrAgentEmail],
            ['full_name' => 'Test HR Agent', 'status' => 'active'],
        );
        if (! $hrAgent->hasRole('hr_agent')) {
            $hrAgent->assignRole('hr_agent');
        }

        // --- Test employee ---
        Employee::updateOrCreate(
            ['email' => $employeeEmail],
            [
                'full_name' => 'Test Employee',
                'convenio_id' => $convenio->id,
                'job_category_id' => $jobCategory->id,
                'territory_id' => $territory->id,
                'work_location' => 'DEV FIXTURE — placeholder location',
                'employment_type' => 'full_time',
                'status' => 'active',
            ],
        );
    }
}
