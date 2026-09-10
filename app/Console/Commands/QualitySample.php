<?php

namespace App\Console\Commands;

use App\Support\QualitySamplingService;
use Illuminate\Console\Command;

/**
 * Sprint 8, Step 6 (plan.md §6.2) — the monthly stratified quality-sample
 * draw. Manual/on-demand (not scheduled — sampling is a human review
 * workflow HR runs once a month, not a nightly job).
 */
class QualitySample extends Command
{
    protected $signature = 'quality:sample
        {--month= : YYYY-MM (default: current month)}
        {--n=20 : how many turns to draw}
        {--seed= : RNG seed (default: deterministic from the month string)}';

    protected $description = 'Draw the monthly stratified quality sample (path × territory, seeded/reproducible).';

    public function handle(QualitySamplingService $service): int
    {
        $month = $this->option('month') ?? now()->format('Y-m');
        $n = (int) $this->option('n');
        $seed = $this->option('seed') !== null ? (int) $this->option('seed') : null;

        $result = $service->draw($month, $n, $seed);

        $this->info("quality:sample {$month} (n={$n}, seed={$result['seed']}): drawn {$result['drawn']} of {$result['total_population']} answered turns across {$result['strata']} strata.");

        return self::SUCCESS;
    }
}
