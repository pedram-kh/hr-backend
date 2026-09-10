<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * EscalationFixAnalytics — Sprint 8, Step 4 (plan.md §3, ADR-0030).
 *
 * The 7g escalation-explanation matrix (`EscalationExplainer::MATRIX`, 39
 * entries, ADR-0029) already IS the `reason.sub_outcome → fix_action`
 * taxonomy this screen needs. This class is a READ + GROUP-BY over
 * `escalation_cards`'s already-computed columns — no new taxonomy, no new
 * write path (plan.md §3.1's TL;DR finding).
 */
class EscalationFixAnalytics
{
    /**
     * plan.md §3.1's exact SQL shape — the spec's own worked example ("41
     * escalations would be prevented by assigning employee groups…") is
     * literally a `group by fix_action` on data that already exists per-card.
     *
     * @return Collection<int,array<string,mixed>>
     */
    public function byFix(Carbon $periodStart, Carbon $periodEnd): Collection
    {
        $rows = DB::select('
            select
              reason,
              explanation_facts->>\'sub_outcome\' as sub_outcome,
              fix_action, fix_surface, fix_link,
              count(*) as card_count,
              count(*) filter (where status in (\'resolved\',\'closed\')) as resolved_count
            from escalation_cards
            where created_at >= ? and created_at < ?
            group by reason, explanation_facts->>\'sub_outcome\', fix_action, fix_surface, fix_link
            order by card_count desc
        ', [$periodStart->toDateTimeString(), $periodEnd->toDateTimeString()]);

        return collect($rows)->map(fn ($r) => (array) $r);
    }

    /** Cards created before 7g's explanation columns landed — still null (plan.md §3.3). */
    public function unexplainedCount(): int
    {
        return (int) DB::table('escalation_cards')->whereNull('explanation_facts')->count();
    }

    /**
     * Board throughput (plan.md §3.2): default metric = resolved_at -
     * created_at per card, grouped by assigned_to. Conversion rate =
     * escalation_resolutions.converted_to_document_id is not null / resolved.
     *
     * @return array<string,mixed>
     */
    public function boardThroughput(Carbon $periodStart, Carbon $periodEnd): array
    {
        $byAgent = DB::table('escalation_cards')
            ->whereNotNull('resolved_at')
            ->whereNotNull('assigned_to')
            ->where('created_at', '>=', $periodStart)
            ->where('created_at', '<', $periodEnd)
            ->select(
                'assigned_to',
                DB::raw('count(*) as resolved_count'),
                DB::raw('avg(extract(epoch from (resolved_at - created_at))) as avg_seconds_to_resolution'),
            )
            ->groupBy('assigned_to')
            ->get()
            ->map(fn ($r) => [
                'assigned_to' => $r->assigned_to,
                'resolved_count' => (int) $r->resolved_count,
                'avg_hours_to_resolution' => round(((float) $r->avg_seconds_to_resolution) / 3600, 1),
            ])
            ->all();

        $totalResolved = (int) DB::table('escalation_cards')
            ->whereIn('status', ['resolved', 'closed'])
            ->where('created_at', '>=', $periodStart)
            ->where('created_at', '<', $periodEnd)
            ->count();

        $converted = (int) DB::table('escalation_resolutions as er')
            ->join('escalation_cards as ec', 'ec.id', '=', 'er.card_id')
            ->whereNotNull('er.converted_to_document_id')
            ->where('ec.created_at', '>=', $periodStart)
            ->where('ec.created_at', '<', $periodEnd)
            ->count();

        return [
            'by_agent' => $byAgent,
            'total_resolved' => $totalResolved,
            'converted_to_document' => $converted,
            'conversion_rate' => $totalResolved > 0 ? round($converted / $totalResolved, 4) : null,
        ];
    }

    /**
     * Per-state timing drill-down (plan.md §3.2) — `escalation_events` walk,
     * available but not the default metric.
     *
     * @return Collection<int,array<string,mixed>>
     */
    public function stateTimingForCard(int $escalationCardId): Collection
    {
        $events = DB::table('escalation_events')
            ->where('escalation_card_id', $escalationCardId)
            ->orderBy('created_at')
            ->get(['type', 'created_at', 'actor_id']);

        $out = [];
        $prev = null;
        foreach ($events as $event) {
            if ($prev !== null) {
                $out[] = [
                    'from_type' => $prev->type,
                    'to_type' => $event->type,
                    'seconds_in_state' => Carbon::parse($prev->created_at)->diffInSeconds(Carbon::parse($event->created_at)),
                ];
            }
            $prev = $event;
        }

        return collect($out);
    }

    /**
     * Publish-fence outcomes (plan.md §3.2) — a straight read of existing 7d
     * data, `escalation_events` rows of type publish_blocked /
     * publish_acknowledged_overlap, reading `detail` (the exact shape
     * `SemanticComparison::toAudit()` writes).
     *
     * @return Collection<int,array<string,mixed>>
     */
    public function fenceOutcomes(Carbon $periodStart, Carbon $periodEnd): Collection
    {
        $rows = DB::table('escalation_events')
            ->whereIn('type', ['publish_blocked', 'publish_acknowledged_overlap'])
            ->where('created_at', '>=', $periodStart)
            ->where('created_at', '<', $periodEnd)
            ->select('type', 'detail', 'created_at')
            ->get();

        return $rows->map(fn ($r) => [
            'type' => $r->type,
            'detail' => json_decode($r->detail ?? '{}', true),
            'created_at' => $r->created_at,
        ]);
    }
}
