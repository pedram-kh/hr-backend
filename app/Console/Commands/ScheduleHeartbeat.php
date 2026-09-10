<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Sprint 8, Step 1 — the scheduler's own proof-of-life.
 *
 * This codebase had NO scheduler wired up before Sprint 8 (`routes/console.php`
 * was a single demo `inspire` command; no `withSchedule()` in `bootstrap/app.php`;
 * the only always-on background process staging ran was the `queue:work` worker,
 * ADR-0025). Every real Sprint-8 job (`stats:rollup`, the coverage snapshot, the
 * nightly cluster job) depends on the scheduler container actually being up and
 * actually ticking — so this command exists purely to make that observable with
 * a one-line log grep, independent of whether any real job has fired yet.
 *
 * `stats:rollup`/coverage-snapshot/cluster jobs register themselves in
 * `bootstrap/app.php`'s `withSchedule()` alongside this one; this one is
 * deliberately kept first and deliberately a no-op (writes nothing to the DB)
 * so "the scheduler is alive" and "a job succeeded" are never the same signal.
 */
class ScheduleHeartbeat extends Command
{
    protected $signature = 'schedule:heartbeat';

    protected $description = 'No-op proof-of-life for the scheduler container (Sprint 8 Step 1) — logs only, writes nothing.';

    public function handle(): int
    {
        Log::info('[schedule:heartbeat] scheduler is alive at '.now()->toIso8601String());
        $this->info('heartbeat logged');

        return self::SUCCESS;
    }
}
