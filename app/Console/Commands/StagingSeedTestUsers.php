<?php

namespace App\Console\Commands;

use App\Models\Convenio;
use App\Models\Employee;
use Database\Seeders\ChatTestUserSeeder;
use Database\Seeders\DocumentTypeSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\TerritorySeeder;
use Database\Seeders\TestUserSeeder;
use Database\Seeders\TopicSeeder;
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
 * TestUserSeeder depends on territories + roles already existing
 * (DatabaseSeeder.php's own ordering: TerritorySeeder, DocumentTypeSeeder,
 * TopicSeeder, RoleSeeder, THEN TestUserSeeder — found live, `migrate
 * --force` alone does not seed any of these). Deliberately NOT running the
 * full DatabaseSeeder: it also calls ChatTestUserSeeder, which depends on
 * the registry import (Session 3, not yet run this session).
 *
 * Safe to re-run: every seeder called here uses updateOrCreate/firstOrCreate
 * throughout.
 */
class StagingSeedTestUsers extends Command
{
    protected $signature = 'staging:seed-test-users {--chat-profiles : also seed the convenio-scoped chat test employees (needs registry:import + salary:import to have run)}';

    protected $description = 'Seed staging test accounts (super_admin, knowledge_editor, auditor, hr_agent, one employee) from SEED_*_EMAIL env vars. Idempotent.';

    public function handle(): int
    {
        foreach (['SEED_ADMIN_EMAIL', 'SEED_EMPLOYEE_EMAIL', 'SEED_EDITOR_EMAIL', 'SEED_AUDITOR_EMAIL', 'SEED_HR_AGENT_EMAIL'] as $var) {
            if (blank(env($var))) {
                $this->error("{$var} is not set — refusing to seed with the example.com defaults on staging. Set it in the environment first.");

                return self::FAILURE;
            }
        }

        foreach ([TerritorySeeder::class, DocumentTypeSeeder::class, TopicSeeder::class, RoleSeeder::class, TestUserSeeder::class] as $seeder) {
            $this->call('db:seed', ['--class' => $seeder, '--force' => true]);
        }

        // The convenio-scoped chat profiles are opt-in because they need the
        // registry AND the salary import to have run (a profile is bound to a real
        // convenio and, for the salary ones, to a category that actually has a
        // row). Seeding them into an empty registry would silently skip them all.
        if ($this->option('chat-profiles')) {
            if (Convenio::query()->doesntExist()) {
                $this->error('--chat-profiles needs the registry: no convenios exist yet. Run registry:import first.');

                return self::FAILURE;
            }
            $this->call('db:seed', ['--class' => ChatTestUserSeeder::class, '--force' => true]);
            $this->table(
                ['employee', 'convenio', 'category'],
                Employee::with(['convenio:id,name', 'jobCategory:id,name'])
                    ->where('email', 'like', 'test-%@example.com')
                    ->orderBy('id')
                    ->get()
                    ->map(fn (Employee $e) => [
                        $e->full_name,
                        $e->convenio?->name ?? '—',
                        $e->jobCategory?->name ?? '— (constrained pick)',
                    ])->all(),
            );
        }

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
