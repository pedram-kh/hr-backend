<?php

namespace App\Console\Commands;

use App\Support\QuestionClusteringService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Sprint 8, Step 5 (plan.md §4.2) — the nightly question-cluster job,
 * scheduled in `bootstrap/app.php` alongside `stats:rollup`/
 * `coverage:snapshot`. Read-only over `chat_messages`; the ONLY write is
 * `question_clusters`/`question_cluster_members` (full rebuild per run_date).
 */
class QuestionsCluster extends Command
{
    protected $signature = 'questions:cluster
        {--from= : period start, YYYY-MM-DD (default: 90 days ago)}
        {--to= : period end, YYYY-MM-DD, exclusive (default: today)}
        {--run-date= : the run_date to write under (default: today)}
        {--threshold= : cosine threshold τ (default: 0.80, plan.md §4.2)}
        {--print : also print the topic breakdown and unanswered ranking (eyes-on use)}';

    protected $description = 'Nightly greedy-threshold question clustering (no LLM label — the medoid is the label).';

    public function handle(QuestionClusteringService $service): int
    {
        $from = $this->option('from') ? Carbon::parse($this->option('from')) : Carbon::today()->subDays(90);
        $to = $this->option('to') ? Carbon::parse($this->option('to')) : Carbon::today();
        $runDate = $this->option('run-date') ? Carbon::parse($this->option('run-date')) : Carbon::today();
        $threshold = $this->option('threshold') !== null ? (float) $this->option('threshold') : QuestionClusteringService::DEFAULT_THRESHOLD;

        $result = $service->run($from, $to, $runDate, $threshold);

        $this->info("questions:cluster {$from->toDateString()}..{$to->toDateString()} (τ={$threshold}): ".
            "{$result['clusters']} clusters from {$result['distinct_texts']} distinct questions ({$result['members']} total turns)");

        if ($this->option('print')) {
            $this->newLine();
            $this->line('Top questions by topic (§4.1, TopicLexicon::matchTopicKeys):');
            foreach ($service->topicBreakdown($from, $to) as $topic => $count) {
                $this->line("  {$topic}: {$count}");
            }

            $this->newLine();
            $this->line('Unanswered ranking (§4.2 step 7, escalation_rate × volume × headcount_weight):');
            foreach ($service->unansweredRanking($runDate) as $row) {
                $this->line(sprintf('  score=%.2f  esc=%.2f  vol=%d  hc=%d  "%s"', $row['score'], $row['escalation_rate'], $row['volume'], $row['headcount_weight'], $row['medoid_text']));
            }
        }

        return self::SUCCESS;
    }
}
