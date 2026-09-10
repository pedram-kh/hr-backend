<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * DeflectionAnalytics — Sprint 8, Step 3 (plan.md §2, ADR-0030).
 *
 * The exact SQL shape from plan.md §2.2, as one shared PHP class used by
 * BOTH the live query (`stats:deflection --live`, the rollup's own
 * correctness proof) and the rollup writer (`stats:rollup`) — so both paths
 * are provably the same definitions, never two independently-drifting copies.
 *
 * Definitions (plan.md §2.1, stated exactly, restated here as code comments
 * so the query and its own justification travel together):
 * - A turn = a message_traces row, joined to its ASSISTANT chat_messages row.
 *   `hr_agent` rows never have a message_traces row — structurally excluded,
 *   not filtered.
 * - `floor_decision.path` is only present on salary_sql / reference_fact /
 *   reference_fact_composition / salary_prose_crosspath turns — every OTHER
 *   turn (the prose/guardrail/off_domain floors) carries
 *   `retrieval_score_floor`/`answer_confidence_floor` instead. The query
 *   below synthesizes the fifth bucket, `'prose'`, exactly where `path` is
 *   absent but a `retrieval_score_floor` key is present (data-model.md
 *   finding, plan.md §1.1) — a guardrail-escalated turn that never reached
 *   the router gets `path = null` (no bucket forced on it; it is not a prose
 *   turn structurally, so it stays `unknown`/null rather than being
 *   mislabelled).
 * - `needs_category` is excluded from the deflection-rate DENOMINATOR
 *   (plan.md §2.1, resolved open question #2) but always counted/returned as
 *   its own figure.
 */
class DeflectionAnalytics
{
    /**
     * The raw per-turn rows for a period + optional scope filter — plan.md
     * §2.2's exact SQL shape. Every number this sprint shows must be
     * reproducible from this one query (the sprint's own hard constraint).
     *
     * @param  array{territory_id?:int,sector_id?:int,convenio_id?:int}  $filters
     */
    public function liveTurns(Carbon $periodStart, Carbon $periodEnd, array $filters = []): Collection
    {
        $sql = "
            select
              mt.id as trace_id,
              am.id as message_id,
              am.created_at as turn_at,
              e.territory_id, cv.sector_id, e.convenio_id,
              coalesce(mt.trace->'floor_decision'->>'outcome', 'unknown') as outcome,
              coalesce(
                mt.trace->'floor_decision'->>'path',
                case when mt.trace->'floor_decision'->>'retrieval_score_floor' is not null then 'prose'
                     when mt.trace->'floor_decision'->>'answer_confidence_floor' is not null then 'prose'
                     else null end
              ) as path,
              mt.trace->'floor_decision'->'authority_used' as authority_used_json
            from message_traces mt
            join chat_messages am on am.id = mt.message_id and am.role = 'assistant'
            join chat_sessions cs on cs.id = am.session_id
            join employees e on e.id = cs.employee_id
            join convenios cv on cv.id = e.convenio_id
            where am.created_at >= ? and am.created_at < ?
        ";
        $bindings = [$periodStart->toDateTimeString(), $periodEnd->toDateTimeString()];

        if (isset($filters['territory_id'])) {
            $sql .= ' and e.territory_id = ?';
            $bindings[] = $filters['territory_id'];
        }
        if (isset($filters['sector_id'])) {
            $sql .= ' and cv.sector_id = ?';
            $bindings[] = $filters['sector_id'];
        }
        if (isset($filters['convenio_id'])) {
            $sql .= ' and e.convenio_id = ?';
            $bindings[] = $filters['convenio_id'];
        }

        $rows = DB::select($sql, $bindings);

        return collect($rows)->map(function ($row) {
            $authorityArr = $row->authority_used_json !== null ? json_decode($row->authority_used_json, true) : null;

            return [
                'trace_id' => $row->trace_id,
                'message_id' => $row->message_id,
                'turn_at' => $row->turn_at,
                'territory_id' => $row->territory_id,
                'sector_id' => $row->sector_id,
                'convenio_id' => $row->convenio_id,
                'outcome' => $row->outcome,
                'path' => $row->path,
                'authority_used_key' => self::authorityKey($authorityArr),
            ];
        });
    }

    /** Sorted+joined authority_used array → a stable string key, or null when absent (plan.md §2.4). */
    public static function authorityKey(?array $authorityUsed): ?string
    {
        if ($authorityUsed === null || $authorityUsed === []) {
            return null;
        }
        $sorted = $authorityUsed;
        sort($sorted);

        return implode('+', $sorted);
    }

    /**
     * Aggregate a set of turn rows (from `liveTurns()`) into the summary the
     * `stats:deflection` command / Analítica screen shows (plan.md §2.1).
     *
     * @return array{answered:int,escalated:int,needs_category:int,deflection_rate:?float,path_split:array<string,int>,authority_split:array<string,int>}
     */
    public function summarize(Collection $turns): array
    {
        $answered = $turns->where('outcome', 'answer')->count();
        $escalated = $turns->where('outcome', 'escalate')->count();
        $needsCategory = $turns->where('outcome', 'needs_category')->count();
        $denominator = $answered + $escalated; // needs_category excluded (§2.1, resolved q2).

        return [
            'answered' => $answered,
            'escalated' => $escalated,
            'needs_category' => $needsCategory,
            'deflection_rate' => $denominator > 0 ? round($answered / $denominator, 4) : null,
            'path_split' => $turns->groupBy(fn ($t) => $t['path'] ?? 'unknown')->map->count()->all(),
            'authority_split' => $turns->groupBy(fn ($t) => $t['authority_used_key'] ?? 'none')->map->count()->all(),
        ];
    }

    /** hr_agent replies (plan.md §2.2) — a separate, always-live query; no message_traces row exists to roll up. */
    public function hrAgentReplies(Carbon $periodStart, Carbon $periodEnd): Collection
    {
        return collect(DB::select("
            select author_admin_id, count(*) as reply_count
            from chat_messages
            where role = 'hr_agent' and created_at >= ? and created_at < ?
            group by author_admin_id
        ", [$periodStart->toDateTimeString(), $periodEnd->toDateTimeString()]));
    }

    /**
     * Satisfaction rate (plan.md §7 — the Feedback item's own tile, wired
     * into the SAME period/scope filters as `summarize()`/`fromRollup()`
     * above, not a second definition). Always live (no rollup): a click is
     * rare and `message_feedback` is tiny — the row-scan concern that drove
     * the deflection rollup does not apply here.
     *
     * @param  array{territory_id?:int,sector_id?:int,convenio_id?:int}  $filters
     * @return array{up:int,down:int,rate:?float}
     */
    public function satisfaction(Carbon $periodStart, Carbon $periodEnd, array $filters = []): array
    {
        $query = DB::table('message_feedback as mf')
            ->join('chat_messages as am', 'am.id', '=', 'mf.message_id')
            ->join('chat_sessions as cs', 'cs.id', '=', 'am.session_id')
            ->join('employees as e', 'e.id', '=', 'cs.employee_id')
            ->join('convenios as cv', 'cv.id', '=', 'e.convenio_id')
            ->where('mf.created_at', '>=', $periodStart->toDateTimeString())
            ->where('mf.created_at', '<', $periodEnd->toDateTimeString());

        if (isset($filters['territory_id'])) {
            $query->where('e.territory_id', $filters['territory_id']);
        }
        if (isset($filters['sector_id'])) {
            $query->where('cv.sector_id', $filters['sector_id']);
        }
        if (isset($filters['convenio_id'])) {
            $query->where('e.convenio_id', $filters['convenio_id']);
        }

        $up = (int) (clone $query)->where('mf.rating', 'up')->count();
        $down = (int) (clone $query)->where('mf.rating', 'down')->count();
        $total = $up + $down;

        return ['up' => $up, 'down' => $down, 'rate' => $total > 0 ? round($up / $total, 4) : null];
    }

    /**
     * Rollup rows for exactly one calendar date — the granular rows AND the
     * "all scopes" totals row, via GROUPING SETS (plan.md §2.4). Returned as
     * plain arrays ready for `analytics_daily_rollups` insert.
     *
     * @return list<array<string,mixed>>
     */
    public function rollupRowsForDate(Carbon $date): array
    {
        $start = $date->copy()->startOfDay();
        $end = $date->copy()->addDay()->startOfDay();

        $sql = "
            with turns as (
                select
                  e.territory_id, cv.sector_id, e.convenio_id,
                  coalesce(mt.trace->'floor_decision'->>'outcome', 'unknown') as outcome,
                  coalesce(
                    mt.trace->'floor_decision'->>'path',
                    case when mt.trace->'floor_decision'->>'retrieval_score_floor' is not null then 'prose'
                         when mt.trace->'floor_decision'->>'answer_confidence_floor' is not null then 'prose'
                         else null end
                  ) as path,
                  mt.trace->'floor_decision'->'authority_used' as authority_used_json
                from message_traces mt
                join chat_messages am on am.id = mt.message_id and am.role = 'assistant'
                join chat_sessions cs on cs.id = am.session_id
                join employees e on e.id = cs.employee_id
                join convenios cv on cv.id = e.convenio_id
                where am.created_at >= ? and am.created_at < ?
            )
            select territory_id, sector_id, convenio_id, path, authority_used_json, outcome, count(*) as turn_count
            from turns
            group by grouping sets (
                (territory_id, sector_id, convenio_id, path, authority_used_json, outcome),
                (territory_id, path, authority_used_json, outcome),
                (sector_id, path, authority_used_json, outcome),
                (convenio_id, path, authority_used_json, outcome),
                (path, authority_used_json, outcome)
            )
        ";

        $rows = DB::select($sql, [$start->toDateTimeString(), $end->toDateTimeString()]);

        $out = [];
        foreach ($rows as $row) {
            $authorityArr = $row->authority_used_json !== null ? json_decode($row->authority_used_json, true) : null;
            $out[] = [
                'date' => $date->toDateString(),
                'territory_id' => $row->territory_id,
                'sector_id' => $row->sector_id,
                'convenio_id' => $row->convenio_id,
                'path' => $row->path,
                'authority_used_key' => self::authorityKey($authorityArr),
                'outcome' => $row->outcome,
                'turn_count' => (int) $row->turn_count,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        return $out;
    }

    /**
     * Read the rollup table for a period + scope filter, re-aggregated the
     * same way `summarize()` does for a live read — used by
     * `stats:deflection` (default mode) and the Analítica screen.
     *
     * @param  array{territory_id?:int,sector_id?:int,convenio_id?:int}  $filters
     */
    public function fromRollup(Carbon $periodStart, Carbon $periodEnd, array $filters = []): array
    {
        // The scope-filtered read uses the GRANULAR rows (all of
        // territory_id/sector_id/convenio_id set per the filter's own
        // dimension, others null) when a filter is given; the unscoped read
        // uses the fully-null "all scopes" totals row set, to avoid double
        // counting across the grouping-set rows written for the same turns.
        $query = DB::table('analytics_daily_rollups')
            ->where('date', '>=', $periodStart->toDateString())
            ->where('date', '<', $periodEnd->toDateString());

        if (isset($filters['territory_id'])) {
            $query->where('territory_id', $filters['territory_id'])->whereNull('sector_id')->whereNull('convenio_id');
        } elseif (isset($filters['sector_id'])) {
            $query->where('sector_id', $filters['sector_id'])->whereNull('territory_id')->whereNull('convenio_id');
        } elseif (isset($filters['convenio_id'])) {
            $query->where('convenio_id', $filters['convenio_id'])->whereNull('territory_id')->whereNull('sector_id');
        } else {
            $query->whereNull('territory_id')->whereNull('sector_id')->whereNull('convenio_id');
        }

        $rows = $query->select('path', 'authority_used_key', 'outcome', 'turn_count')->get();

        $answered = (int) $rows->where('outcome', 'answer')->sum('turn_count');
        $escalated = (int) $rows->where('outcome', 'escalate')->sum('turn_count');
        $needsCategory = (int) $rows->where('outcome', 'needs_category')->sum('turn_count');
        $denominator = $answered + $escalated;

        return [
            'answered' => $answered,
            'escalated' => $escalated,
            'needs_category' => $needsCategory,
            'deflection_rate' => $denominator > 0 ? round($answered / $denominator, 4) : null,
            'path_split' => $rows->groupBy(fn ($r) => $r->path ?? 'unknown')->map(fn ($g) => (int) $g->sum('turn_count'))->all(),
            'authority_split' => $rows->groupBy(fn ($r) => $r->authority_used_key ?? 'none')->map(fn ($g) => (int) $g->sum('turn_count'))->all(),
        ];
    }
}
