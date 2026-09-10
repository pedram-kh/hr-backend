<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\DeflectionAnalytics;
use App\Support\EscalationFixAnalytics;
use App\Support\QuestionClusteringService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Sprint 8 (plan.md §2/§3/§4, §9) — the Analítica screen's read API. Gated
 * on `analytics.view` in the route group (super_admin/hr_agent/auditor).
 * Every number here is reproducible from `stats:deflection`/
 * `stats:escalations-by-fix`/`questions:cluster` — this controller is a thin
 * read wrapper over the SAME services those commands call, never a second
 * copy of a definition.
 */
class AnalyticsController extends Controller
{
    /**
     * §2 — deflection/outcomes. `live=true` reads `message_traces` directly
     * (cheap for a short period); the default reads the nightly rollup
     * (plan.md §2.4's "refresh today" escape hatch is `stats:rollup
     * --date=today` run manually/by an admin action, not wired here).
     */
    public function deflection(Request $request, DeflectionAnalytics $analytics): JsonResponse
    {
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'territory_id' => ['nullable', 'integer'],
            'sector_id' => ['nullable', 'integer'],
            'convenio_id' => ['nullable', 'integer'],
            'live' => ['nullable', 'boolean'],
        ]);

        $from = isset($data['from']) ? Carbon::parse($data['from']) : Carbon::today()->subDays(30);
        $to = isset($data['to']) ? Carbon::parse($data['to'])->addDay() : Carbon::today()->addDay();
        $filters = array_filter([
            'territory_id' => $data['territory_id'] ?? null,
            'sector_id' => $data['sector_id'] ?? null,
            'convenio_id' => $data['convenio_id'] ?? null,
        ], fn ($v) => $v !== null);

        $summary = ! empty($data['live'])
            ? $analytics->summarize($analytics->liveTurns($from, $to, $filters))
            : $analytics->fromRollup($from, $to, $filters);

        $hrAgentReplies = $analytics->hrAgentReplies($from, $to);
        $satisfaction = $analytics->satisfaction($from, $to, $filters);

        return response()->json([
            'period' => ['from' => $from->toDateString(), 'to' => $to->copy()->subDay()->toDateString()],
            'summary' => $summary,
            'hr_agent_replies' => $hrAgentReplies,
            'satisfaction' => $satisfaction,
        ]);
    }

    /** §3 — the reason→sub_outcome→fix_action grouping + board throughput + fence outcomes. */
    public function escalationsByFix(Request $request, EscalationFixAnalytics $analytics): JsonResponse
    {
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);
        $from = isset($data['from']) ? Carbon::parse($data['from']) : Carbon::today()->subDays(30);
        $to = isset($data['to']) ? Carbon::parse($data['to'])->addDay() : Carbon::today()->addDay();

        return response()->json([
            'period' => ['from' => $from->toDateString(), 'to' => $to->copy()->subDay()->toDateString()],
            'by_fix' => $analytics->byFix($from, $to),
            'unexplained_count' => $analytics->unexplainedCount(),
            'board_throughput' => $analytics->boardThroughput($from, $to),
            'fence_outcomes' => $analytics->fenceOutcomes($from, $to),
        ]);
    }

    /** §4 — the latest cluster run + topic breakdown + unanswered ranking. */
    public function clusters(Request $request, QuestionClusteringService $service): JsonResponse
    {
        $data = $request->validate([
            'run_date' => ['nullable', 'date'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $runDate = isset($data['run_date'])
            ? Carbon::parse($data['run_date'])
            : Carbon::parse(DB::table('question_clusters')->max('run_date') ?? Carbon::today()->toDateString());

        $clusters = DB::table('question_clusters')
            ->where('run_date', $runDate->toDateString())
            ->orderByDesc('member_count')
            ->get();

        $from = isset($data['from']) ? Carbon::parse($data['from']) : $runDate->copy()->subDays(90);
        $to = isset($data['to']) ? Carbon::parse($data['to']) : $runDate->copy();

        return response()->json([
            'run_date' => $runDate->toDateString(),
            'clusters' => $clusters,
            'topic_breakdown' => $service->topicBreakdown($from, $to),
            'unanswered_ranking' => $service->unansweredRanking($runDate),
        ]);
    }
}
