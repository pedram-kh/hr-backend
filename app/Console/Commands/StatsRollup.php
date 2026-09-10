<?php

namespace App\Console\Commands;

use App\Support\DeflectionAnalytics;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Sprint 8, Step 3 (plan.md §2.4) — `stats:rollup`. (Re)computes
 * `analytics_daily_rollups` for one date or a date range. This is BOTH the
 * nightly scheduled job (`bootstrap/app.php`, defaults to yesterday) AND the
 * screen's manual "refresh today" escape hatch (same code path, `--date`
 * pointed at today) — one implementation, two callers, per plan.md §2.4's
 * decision not to maintain two "live" vs "rolled up" code paths.
 *
 * Idempotent per date: delete-then-insert, never upsert-per-row, so a re-run
 * is a clean replace (safe to run twice for the same date).
 */
class StatsRollup extends Command
{
    protected $signature = 'stats:rollup {--date= : a single date, YYYY-MM-DD (default: yesterday)} {--from= : range start, YYYY-MM-DD} {--to= : range end, YYYY-MM-DD (inclusive)}';

    protected $description = 'Compute/replace analytics_daily_rollups for a date or date range (idempotent).';

    public function handle(DeflectionAnalytics $analytics): int
    {
        $dates = $this->resolveDates();

        foreach ($dates as $date) {
            $rows = $analytics->rollupRowsForDate($date);

            DB::transaction(function () use ($date, $rows) {
                DB::table('analytics_daily_rollups')->where('date', $date->toDateString())->delete();
                foreach (array_chunk($rows, 200) as $chunk) {
                    DB::table('analytics_daily_rollups')->insert($chunk);
                }
            });

            $this->info("stats:rollup {$date->toDateString()}: ".count($rows).' rows');
        }

        return self::SUCCESS;
    }

    /** @return list<Carbon> */
    private function resolveDates(): array
    {
        if ($this->option('from') && $this->option('to')) {
            $from = Carbon::parse($this->option('from'))->startOfDay();
            $to = Carbon::parse($this->option('to'))->startOfDay();
            $dates = [];
            for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
                $dates[] = $d->copy();
            }

            return $dates;
        }

        $date = $this->option('date') ? Carbon::parse($this->option('date')) : Carbon::yesterday();

        return [$date->startOfDay()];
    }
}
