<?php

namespace App\Console\Commands;

use App\Support\EscalationFixAnalytics;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Sprint 8, Step 4 (plan.md §3) — prints the escalations-by-fix board
 * exactly as the Analítica screen's fix tab will show it, plus board
 * throughput and publish-fence outcomes. A read over
 * `EscalationExplainer::MATRIX`-computed columns; no new taxonomy.
 */
class StatsEscalationsByFix extends Command
{
    protected $signature = 'stats:escalations-by-fix {--from=} {--to=}';

    protected $description = 'Print the escalations-by-fix board (group by reason/sub_outcome/fix_action) + throughput + fence outcomes.';

    public function handle(EscalationFixAnalytics $analytics): int
    {
        $from = $this->option('from') ? Carbon::parse($this->option('from')) : Carbon::today()->subDays(90);
        $to = $this->option('to') ? Carbon::parse($this->option('to')) : Carbon::today();

        $byFix = $analytics->byFix($from, $to);
        $this->info("Escalations by fix, {$from->toDateString()} .. {$to->toDateString()}:");
        foreach ($byFix as $row) {
            $this->line(sprintf(
                '  %s.%s → %s [%s] (%d cards, %d resolved)',
                $row['reason'], $row['sub_outcome'] ?? '(null)', $row['fix_action'] ?? '(none)', $row['fix_surface'] ?? '',
                $row['card_count'], $row['resolved_count'],
            ));
        }

        $unexplained = $analytics->unexplainedCount();
        $this->line("  unexplained (pre-7g, null explanation_facts): {$unexplained}");

        $throughput = $analytics->boardThroughput($from, $to);
        $this->newLine();
        $this->info('Board throughput:');
        $this->line('  total resolved: '.$throughput['total_resolved']);
        $this->line('  converted to document: '.$throughput['converted_to_document']);
        $this->line('  conversion rate: '.($throughput['conversion_rate'] !== null ? number_format($throughput['conversion_rate'] * 100, 1).'%' : 'n/a'));
        foreach ($throughput['by_agent'] as $agent) {
            $this->line("  admin_id {$agent['assigned_to']}: {$agent['resolved_count']} resolved, avg {$agent['avg_hours_to_resolution']}h to resolution");
        }

        $fence = $analytics->fenceOutcomes($from, $to);
        $this->newLine();
        $this->info('Publish-fence outcomes: '.$fence->count());
        foreach ($fence->groupBy('type') as $type => $group) {
            $this->line("  {$type}: {$group->count()}");
        }

        return self::SUCCESS;
    }
}
