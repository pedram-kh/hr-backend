<?php

namespace App\Console\Commands;

use App\Support\CorpusCoverageService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Sprint 8, Step 2 (plan.md §5.5) — the nightly coverage-gap snapshot, run
 * from `bootstrap/app.php`'s scheduler alongside `stats:rollup`. Idempotent
 * per date (delete-then-insert), same discipline as `stats:rollup` (§2.4).
 */
class CoverageSnapshot extends Command
{
    protected $signature = 'coverage:snapshot {--date= : snapshot date, YYYY-MM-DD (defaults to today)}';

    protected $description = 'Write today\'s (or --date\'s) coverage grid into coverage_snapshots (additive history for the gaps-closed trend).';

    public function handle(CorpusCoverageService $service): int
    {
        $date = $this->option('date') ? Carbon::parse($this->option('date')) : Carbon::today();
        $grid = $service->grid($date);

        DB::transaction(function () use ($grid, $date) {
            DB::table('coverage_snapshots')->where('snapshot_date', $date->toDateString())->delete();

            $rows = [];
            foreach ($grid as $row) {
                $rows[] = [
                    'snapshot_date' => $date->toDateString(),
                    'convenio_id' => $row['convenio_id'],
                    'knowledge_type' => 'prose',
                    'covered' => $row['prose']['covered'],
                    'amendment_only' => $row['prose']['amendment_only'],
                    'group_only' => false,
                    'reason_code' => $row['prose']['reason_code'],
                    'detail' => $row['prose']['detail'],
                    'headcount' => $row['headcount'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
                $rows[] = [
                    'snapshot_date' => $date->toDateString(),
                    'convenio_id' => $row['convenio_id'],
                    'knowledge_type' => 'salary',
                    'covered' => $row['salary']['covered'],
                    'amendment_only' => false,
                    'group_only' => false,
                    'reason_code' => $row['salary']['reason_code'],
                    'detail' => $row['salary']['detail'],
                    'headcount' => $row['headcount'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
                $rows[] = [
                    'snapshot_date' => $date->toDateString(),
                    'convenio_id' => $row['convenio_id'],
                    'knowledge_type' => 'facts',
                    'covered' => $row['facts']['covered'],
                    'amendment_only' => false,
                    'group_only' => $row['facts']['group_only'],
                    'reason_code' => $row['facts']['reason_code'],
                    'detail' => $row['facts']['detail'],
                    'headcount' => $row['headcount'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
                $rows[] = [
                    'snapshot_date' => $date->toDateString(),
                    'convenio_id' => $row['convenio_id'],
                    'knowledge_type' => 'rulings',
                    'covered' => $row['rulings']['covered'],
                    'amendment_only' => false,
                    'group_only' => false,
                    'reason_code' => $row['rulings']['reason_code'],
                    'detail' => $row['rulings']['detail'],
                    'headcount' => $row['headcount'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            foreach (array_chunk($rows, 200) as $chunk) {
                DB::table('coverage_snapshots')->insert($chunk);
            }
        });

        $this->info('coverage:snapshot wrote '.(count($grid) * 4)." rows for {$date->toDateString()}");

        return self::SUCCESS;
    }
}
