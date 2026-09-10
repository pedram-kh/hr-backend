<?php

namespace App\Support;

use App\Services\ExtractionClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

// Note: TopicLexicon lives in this same namespace (App\Support), so
// `topicBreakdown()` below calls it unqualified — no import needed.

/**
 * QuestionClusteringService — Sprint 8, Step 5 (plan.md §4.2, ADR-0030).
 *
 * Nightly, BATCH clustering of employee questions — never per-question, never
 * on the employee path. Algorithm (plan.md §4.2, stated exactly):
 *
 * 1. Pull every DISTINCT user-role `chat_messages.content` string in the
 *    period (dedupe before embedding — BGE-M3 is deterministic).
 * 2. Call `POST /embed-batch` (hr-ai) once per batch of ≤256 strings.
 * 3. GREEDY SINGLE-LINK clustering by cosine threshold τ (not k-means — no
 *    k to choose, deterministic, incremental-friendly). Vectors are unit-
 *    normalized (BGE-M3), so cosine similarity IS the dot product.
 * 4. Threshold τ = 0.80 (a starting assumption, not a measurement — plan.md
 *    §12 resolved q3: logged per-cluster, not silently treated as final).
 * 5. The label is the MEDOID (highest mean similarity to every other member)
 *    — never an LLM summary (hard constraint).
 * 6. Cadence: nightly, alongside `stats:rollup`/`coverage:snapshot`.
 * 7. "Unanswered" ranking = escalation_rate × volume × headcount-of-askers
 *    — reuses `CorpusCoverageService::headcounts()`, one shared helper.
 *
 * Deliberately a FULL REBUILD per run (delete this run_date's rows, recluster
 * from scratch), not an incremental append — simpler, and cheap at today's
 * real corpus size (plan.md §4.2's own "volume reality check": a threshold
 * pass over a few hundred distinct strings is instant). Revisit if/when
 * volume ever makes a full rebuild expensive (flagged in review.md, not
 * silently deferred).
 */
class QuestionClusteringService
{
    public const DEFAULT_THRESHOLD = 0.80;

    public function __construct(private readonly ExtractionClient $hrAi)
    {
    }

    /**
     * Run the full pipeline for one period, writing `question_clusters` +
     * `question_cluster_members` for `$runDate` (delete-then-insert, per the
     * codebase's standing idempotency idiom).
     *
     * @return array{clusters:int,distinct_texts:int,members:int}
     */
    public function run(Carbon $periodStart, Carbon $periodEnd, Carbon $runDate, float $threshold = self::DEFAULT_THRESHOLD): array
    {
        $messages = DB::table('chat_messages')
            ->where('role', 'user')
            ->where('created_at', '>=', $periodStart)
            ->where('created_at', '<', $periodEnd)
            ->select('id', 'content', 'session_id', 'created_at')
            ->get();

        if ($messages->isEmpty()) {
            $this->replaceRunDate($runDate, []);

            return ['clusters' => 0, 'distinct_texts' => 0, 'members' => 0];
        }

        $distinctTexts = $messages->pluck('content')->unique()->values()->all();
        $vectors = $this->embedAll($distinctTexts);

        $textToVector = [];
        foreach ($distinctTexts as $i => $text) {
            $textToVector[$text] = $vectors[$i];
        }

        $clusters = $this->clusterGreedy($distinctTexts, $textToVector, $threshold);

        $headcounts = app(CorpusCoverageService::class)->headcounts();
        $employeeByMessageId = $this->employeesByMessage($messages);
        $convenioByEmployeeId = DB::table('employees')->pluck('convenio_id', 'id');

        $messagesByContent = $messages->groupBy('content');

        $rows = [];
        foreach ($clusters as $memberTexts) {
            [$medoidText, $minSim, $maxSim] = $this->medoid($memberTexts, $textToVector);

            /** @var \Illuminate\Support\Collection $clusterMessages */
            $clusterMessages = collect($memberTexts)->flatMap(fn ($t) => $messagesByContent->get($t, collect()));
            $messageIds = $clusterMessages->pluck('id')->all();

            $escalatedCount = DB::table('escalation_cards')->whereIn('source_message_id', $messageIds)->distinct('source_message_id')->count('source_message_id');
            $escalationRate = count($messageIds) > 0 ? round($escalatedCount / count($messageIds), 4) : null;

            $topReason = DB::table('escalation_cards')->whereIn('source_message_id', $messageIds)
                ->select('reason', DB::raw('count(*) as c'))->groupBy('reason')->orderByDesc('c')->value('reason');

            $askingEmployeeIds = $clusterMessages->map(fn ($m) => $employeeByMessageId[$m->id] ?? null)->filter()->unique()->values();
            $headcountWeight = 0;
            foreach ($askingEmployeeIds as $empId) {
                $convenioId = $convenioByEmployeeId[$empId] ?? null;
                $headcountWeight += $convenioId !== null ? ($headcounts[$convenioId] ?? 0) : 0;
            }

            $rows[] = [
                'cluster' => [
                    'run_date' => $runDate->toDateString(),
                    'medoid_text' => $medoidText,
                    'distinct_text_count' => count($memberTexts),
                    'member_count' => count($messageIds),
                    'min_similarity' => $minSim,
                    'max_similarity' => $maxSim,
                    'threshold_used' => $threshold,
                    'first_seen_at' => $clusterMessages->min('created_at'),
                    'last_seen_at' => $clusterMessages->max('created_at'),
                    'top_escalation_reason' => $topReason,
                    'escalation_rate' => $escalationRate,
                    'headcount_weight' => $headcountWeight,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                'message_ids' => $messageIds,
            ];
        }

        $this->replaceRunDate($runDate, $rows);

        return [
            'clusters' => count($clusters),
            'distinct_texts' => count($distinctTexts),
            'members' => $messages->count(),
        ];
    }

    /** @param list<string> $texts @return list<list<float>> */
    private function embedAll(array $texts): array
    {
        $cap = (int) config('services.hr_ai.embed_batch_cap', 256);
        $vectors = [];
        foreach (array_chunk($texts, max(1, $cap)) as $chunk) {
            $vectors = array_merge($vectors, $this->hrAi->embedBatch($chunk));
        }

        return $vectors;
    }

    /**
     * Greedy single-link clustering (plan.md §4.2 step 3): join the nearest
     * EXISTING cluster if cosine >= τ to ANY current member, else start a
     * new cluster. Deterministic given input order.
     *
     * @param  list<string>  $texts
     * @param  array<string,list<float>>  $textToVector
     * @return list<list<string>> list of clusters, each a list of member texts
     */
    private function clusterGreedy(array $texts, array $textToVector, float $threshold): array
    {
        $clusters = [];
        foreach ($texts as $text) {
            $vector = $textToVector[$text];
            $joined = false;
            foreach ($clusters as $ci => $members) {
                foreach ($members as $memberText) {
                    if (self::cosine($vector, $textToVector[$memberText]) >= $threshold) {
                        $clusters[$ci][] = $text;
                        $joined = true;
                        break 2;
                    }
                }
            }
            if (! $joined) {
                $clusters[] = [$text];
            }
        }

        return $clusters;
    }

    /**
     * The medoid (plan.md §4.2 step 5): the member with the highest MEAN
     * similarity to every other member — never an LLM summary. Also returns
     * the cluster's min/max pairwise similarity (logged for later τ
     * recalibration, plan.md §12 resolved q3).
     *
     * @param  list<string>  $memberTexts
     * @param  array<string,list<float>>  $textToVector
     * @return array{0:string,1:?float,2:?float} [medoid_text, min_sim, max_sim]
     */
    private function medoid(array $memberTexts, array $textToVector): array
    {
        if (count($memberTexts) === 1) {
            return [$memberTexts[0], null, null];
        }

        $best = null;
        $bestMean = -INF;
        $allSims = [];

        foreach ($memberTexts as $a) {
            $sims = [];
            foreach ($memberTexts as $b) {
                if ($a === $b) {
                    continue;
                }
                $sim = self::cosine($textToVector[$a], $textToVector[$b]);
                $sims[] = $sim;
                $allSims[] = $sim;
            }
            $mean = array_sum($sims) / count($sims);
            if ($mean > $bestMean) {
                $bestMean = $mean;
                $best = $a;
            }
        }

        return [$best, min($allSims), max($allSims)];
    }

    /** Cosine similarity — unit-normalized vectors, so this IS the dot product (plan.md §4.2). */
    public static function cosine(array $a, array $b): float
    {
        $sum = 0.0;
        $n = min(count($a), count($b));
        for ($i = 0; $i < $n; $i++) {
            $sum += $a[$i] * $b[$i];
        }

        return $sum;
    }

    /** @return array<int,int|null> chat_message_id => employee_id */
    private function employeesByMessage($messages): array
    {
        $sessionIds = $messages->pluck('session_id')->unique()->values();
        $sessionToEmployee = DB::table('chat_sessions')->whereIn('id', $sessionIds)->pluck('employee_id', 'id');

        $out = [];
        foreach ($messages as $m) {
            $out[$m->id] = $sessionToEmployee[$m->session_id] ?? null;
        }

        return $out;
    }

    /**
     * §4.1 — "top questions by topic": `TopicLexicon::matchTopicKeys()` applied
     * to EVERY user turn in the period, grouped and counted. A direct call
     * into the existing pure lexicon function — no new vocabulary, no new
     * matching code, and INDEPENDENT of clustering (a turn with no anchor
     * match at all contributes to no topic — not an error, just untagged).
     *
     * @return array<string,int> topic_key => turn count, sorted desc by count
     */
    public function topicBreakdown(Carbon $periodStart, Carbon $periodEnd): array
    {
        $counts = [];
        DB::table('chat_messages')
            ->where('role', 'user')
            ->where('created_at', '>=', $periodStart)
            ->where('created_at', '<', $periodEnd)
            ->select('content')
            ->orderBy('id')
            ->chunk(500, function ($chunk) use (&$counts) {
                foreach ($chunk as $row) {
                    foreach (array_keys(TopicLexicon::matchTopicKeys($row->content)) as $topicKey) {
                        $counts[$topicKey] = ($counts[$topicKey] ?? 0) + 1;
                    }
                }
            });

        arsort($counts);

        return $counts;
    }

    /**
     * §4.2 step 7 — "unanswered" ranking: escalation_rate(cluster) ×
     * volume(cluster) × sum(headcount of the asking employees' scopes).
     * Reads the already-persisted `question_clusters` row for `$runDate` —
     * every term of the formula (escalation_rate, member_count as volume,
     * headcount_weight) was computed once in `run()` and reused here, not
     * recomputed (the same shared-helper discipline as the coverage map,
     * §4.2 step 7's own wording).
     *
     * @return list<array{cluster_id:int,medoid_text:string,score:float,escalation_rate:float,volume:int,headcount_weight:int}>
     */
    public function unansweredRanking(Carbon $runDate, int $limit = 20): array
    {
        $clusters = DB::table('question_clusters')->where('run_date', $runDate->toDateString())->get();

        $ranked = $clusters->map(function ($c) {
            $rate = (float) ($c->escalation_rate ?? 0.0);
            $score = $rate * $c->member_count * $c->headcount_weight;

            return [
                'cluster_id' => $c->id,
                'medoid_text' => $c->medoid_text,
                'score' => $score,
                'escalation_rate' => $rate,
                'volume' => $c->member_count,
                'headcount_weight' => $c->headcount_weight,
            ];
        })->sortByDesc('score')->take($limit)->values()->all();

        return $ranked;
    }

    /** @param list<array{cluster:array<string,mixed>,message_ids:list<int>}> $rows */
    private function replaceRunDate(Carbon $runDate, array $rows): void
    {
        DB::transaction(function () use ($runDate, $rows) {
            $existingIds = DB::table('question_clusters')->where('run_date', $runDate->toDateString())->pluck('id');
            if ($existingIds->isNotEmpty()) {
                DB::table('question_cluster_members')->whereIn('question_cluster_id', $existingIds)->delete();
                DB::table('question_clusters')->whereIn('id', $existingIds)->delete();
            }

            foreach ($rows as $row) {
                $clusterId = DB::table('question_clusters')->insertGetId($row['cluster']);
                $memberRows = array_map(fn ($msgId) => [
                    'question_cluster_id' => $clusterId,
                    'chat_message_id' => $msgId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ], $row['message_ids']);
                foreach (array_chunk($memberRows, 200) as $chunk) {
                    if ($chunk !== []) {
                        DB::table('question_cluster_members')->insert($chunk);
                    }
                }
            }
        });
    }
}
