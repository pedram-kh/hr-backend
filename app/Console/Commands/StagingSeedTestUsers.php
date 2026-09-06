<?php

namespace App\Console\Commands;

use Database\Seeders\TestUserSeeder;
use Illuminate\Console\Command;

/**
 * hr-docs staging plan (build Session 2) — a thin, explicit entrypoint for
 * seeding staging's test accounts, distinct from local dev's own seeding
 * path (`composer setup` never runs on staging).
 *
 * Deliberately reuses TestUserSeeder as-is (no logic duplicated): it already
 * creates one admin per role (super_admin, knowledge_editor, auditor,
 * hr_agent) plus one test employee, all driven by the SEED_*_EMAIL env vars
 * (see hr-backend/.env.staging.example) — real addresses Pedram controls,
 * not example.com. This command only adds a staging-safe, explicit name and
 * a printed summary so the deploy runbook has one clear step to point at.
 *
 * Safe to re-run: TestUserSeeder uses updateOrCreate/firstOrCreate throughout.
 */
class StagingSeedTestUsers extends Command
{
    protected $signature = 'staging:seed-test-users';

    protected $description = 'Seed staging test accounts (super_admin, knowledge_editor, auditor, hr_agent, one employee) from SEED_*_EMAIL env vars. Idempotent.';

    public function handle(): int
    {
        foreach (['SEED_ADMIN_EMAIL', 'SEED_EMPLOYEE_EMAIL', 'SEED_EDITOR_EMAIL', 'SEED_AUDITOR_EMAIL', 'SEED_HR_AGENT_EMAIL'] as $var) {
            if (blank(env($var))) {
                $this->error("{$var} is not set — refusing to seed with the example.com defaults on staging. Set it in the environment first.");

                return self::FAILURE;
            }
        }

        $this->call('db:seed', ['--class' => TestUserSeeder::class, '--force' => true]);

        $this->info('Seeded staging test accounts:');
        $this->table(
            ['role', 'email'],
            [
                ['super_admin', env('SEED_ADMIN_EMAIL')],
                ['knowledge_editor', env('SEED_EDITOR_EMAIL')],
                ['auditor', env('SEED_AUDITOR_EMAIL')],
                ['hr_agent', env('SEED_HR_AGENT_EMAIL')],
                ['employee', env('SEED_EMPLOYEE_EMAIL')],
            ],
        );

        return self::SUCCESS;
    }
}
