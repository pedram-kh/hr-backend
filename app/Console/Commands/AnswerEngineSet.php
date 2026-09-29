<?php

namespace App\Console\Commands;

use App\Models\Admin;
use App\Models\AnswerEngineSetting;
use Illuminate\Console\Command;

/**
 * Sprint 13, build step 2 (plan.md §E.15 step 2, §F.14) — set or clear the
 * runtime engine override without a deploy. Restricted to a super_admin: the
 * command runs with server/CLI access already (no HTTP auth boundary to
 * enforce here), but the *audit* trail — who flipped the switch — mirrors
 * every other admin-driven config write in this codebase (`GuardrailConfigService
 * ::update()`, `AnswerModelSetting::setKey()`), so `--admin` is required and
 * must resolve to an admin holding the `super_admin` role; a non-super_admin
 * or unknown email refuses the write with no side effect.
 */
class AnswerEngineSet extends Command
{
    protected $signature = 'answer-engine:set
        {engine? : classic|agent, or omit with --clear to remove the override}
        {--admin= : the acting admin\'s email (must hold super_admin) — required, for the audit trail}
        {--clear : remove the override — the env HR_ANSWER_ENGINE baseline takes effect again}';

    protected $description = 'Set or clear the runtime answer-engine override (super_admin only; no deploy needed).';

    public function handle(): int
    {
        $adminEmail = $this->option('admin');
        if (! $adminEmail) {
            $this->error('--admin=<email> is required (the acting admin, for the audit trail).');

            return self::FAILURE;
        }

        $admin = Admin::where('email', strtolower(trim($adminEmail)))->first();
        if ($admin === null) {
            $this->error("No admin found with email '{$adminEmail}'.");

            return self::FAILURE;
        }
        if (! $admin->hasRole('super_admin')) {
            $this->error("'{$adminEmail}' is not a super_admin — refusing to change the answer engine.");

            return self::FAILURE;
        }

        $clear = (bool) $this->option('clear');
        $engine = $this->argument('engine');

        if ($clear) {
            if ($engine !== null) {
                $this->error('Pass either an engine or --clear, not both.');

                return self::FAILURE;
            }
            AnswerEngineSetting::current()->setEngine(null, $admin->id);
            $this->info('Override cleared — the HR_ANSWER_ENGINE env baseline ('.config('hr.answer_engine').') now takes effect.');

            return self::SUCCESS;
        }

        if ($engine === null || ! in_array($engine, AnswerEngineSetting::VALID_ENGINES, true)) {
            $this->error('engine must be one of: '.implode(', ', AnswerEngineSetting::VALID_ENGINES).' (or pass --clear).');

            return self::FAILURE;
        }

        AnswerEngineSetting::current()->setEngine($engine, $admin->id);
        $this->info("Override set: every turn now uses the '{$engine}' engine, by {$adminEmail}.");

        return self::SUCCESS;
    }
}
