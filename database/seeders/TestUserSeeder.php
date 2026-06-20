<?php

namespace Database\Seeders;

use App\Models\Admin;
use App\Models\Convenio;
use App\Models\ConvenioJobCategory;
use App\Models\Employee;
use App\Models\Province;
use App\Models\Sector;
use Illuminate\Database\Seeder;

/**
 * One test admin + one test employee (real emails the tester controls, from .env).
 *
 * Review C6 (confirms Q3): employees.convenio_id / province_id are NOT NULL, but
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
        $province = Province::where('code', '01')->firstOrFail(); // Álava (seeded)

        $sector = Sector::firstOrCreate(
            ['name' => self::FIXTURE_LABEL],
            ['aliases' => []],
        );

        $convenio = Convenio::firstOrCreate(
            ['numero' => 'DEV-FIXTURE-0001'],
            [
                'name' => self::FIXTURE_LABEL,
                'province_id' => $province->id,
                'sector_id' => $sector->id,
                'notes' => 'Dev fixture only — replaced by the real registry import in Sprint 1.',
            ],
        );

        $jobCategory = ConvenioJobCategory::firstOrCreate(
            ['convenio_id' => $convenio->id, 'name' => self::FIXTURE_LABEL],
            ['group_code' => null],
        );

        // --- Test admin ---
        $admin = Admin::updateOrCreate(
            ['email' => $adminEmail],
            ['full_name' => 'Test Admin', 'status' => 'active'],
        );
        if (! $admin->hasRole('super_admin')) {
            $admin->assignRole('super_admin');
        }

        // --- Test employee ---
        Employee::updateOrCreate(
            ['email' => $employeeEmail],
            [
                'full_name' => 'Test Employee',
                'convenio_id' => $convenio->id,
                'job_category_id' => $jobCategory->id,
                'province_id' => $province->id,
                'work_location' => 'DEV FIXTURE — placeholder location',
                'employment_type' => 'full_time',
                'status' => 'active',
            ],
        );
    }
}
