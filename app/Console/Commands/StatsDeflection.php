<?php

namespace App\Console\Commands;

use App\Support\DeflectionAnalytics;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Sprint 8, Step 3 (plan.md §2.4) — `stats:deflection`. Prints exactly the
 * numbers the Analítica screen shows: answered/escalated/needs_category
 * counts, deflection rate, path split, authority split, hr_agent reply
 * count. Reads the ROLLUP table by default; `--live` re-runs the raw query
 * directly (plan.md §2.2) — the rollup's own correctness test, and the
 * definitions test (`Sprint8AnalyticsDefinitionsTest`) asserts both agree.
 */
class StatsDeflection extends Command
{
    protected $signature = 'stats:deflection
        {--from= : period start, YYYY-MM-DD (default: 30 days ago)}
        {--to= : period end, YYYY-MM-DD, EXCLUSIVE (default: today)}
        {--territory= : territory_id filter}
        {--sector= : sector_id filter}
        {--convenio= : convenio_id filter}
        {--live : bypass the rollup table and re-run the raw per-turn query}';

    protected $description = 'Print deflection/outcome numbers for a period + optional scope filter.';

    public function handle(DeflectionAnalytics $analytics): int
    {
        $from = $this->option('from') ? Carbon::parse($this->option('from')) : Carbon::today()->subDays(30);
        $to = $this->option('to') ? Carbon::parse($this->option('to')) : Carbon::today();

        $filters = array_filter([
            'territory_id' => $this->option('territory') !== null ? (int) $this->option('territory') : null,
            'sector_id' => $this->option('sector') !== null ? (int) $this->option('sector') : null,
            'convenio_id' => $this->option('convenio') !== null ? (int) $this->option('convenio') : null,
        ], fn ($v) => $v !== null);

        if ($this->option('live')) {
            $turns = $analytics->liveTurns($from, $to, $filters);
            $summary = $analytics->summarize($turns);
            $mode = 'live';
        } else {
            $summary = $analytics->fromRollup($from, $to, $filters);
            $mode = 'rollup';
        }

        $hrAgent = $analytics->hrAgentReplies($from, $to);

        $this->info("stats:deflection [{$mode}] {$from->toDateString()} .. {$to->toDateString()}");
        $this->line('  answered:        '.$summary['answered']);
        $this->line('  escalated:       '.$summary['escalated']);
        $this->line('  needs_category:  '.$summary['needs_category'].' (excluded from the ratio, per plan.md §2.1)');
        $this->line('  deflection_rate: '.($summary['deflection_rate'] !== null ? number_format($summary['deflection_rate'] * 100, 2).'%' : 'n/a (no answered+escalated turns)'));
        $this->line('  path split:      '.json_encode($summary['path_split']));
        $this->line('  authority split: '.json_encode($summary['authority_split']));
        $this->line('  hr_agent replies (by admin_id): '.$hrAgent->pluck('reply_count', 'author_admin_id')->toJson());

        return self::SUCCESS;
    }
}
