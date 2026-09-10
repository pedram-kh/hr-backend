<?php

namespace App\Support;

use App\Models\Admin;
use App\Models\ChatMessage;
use App\Models\EscalationCard;
use App\Models\QualitySample;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * QualitySamplingService — Sprint 8, Step 6 (plan.md §6, ADR-0030).
 *
 * The stratified monthly draw (§6.2), the "wrong → task with fix link" rule
 * (§6.4), and the monthly trend read (§6.5) — one shared service so the
 * `quality:sample` command and the (Step 7) review-screen controller call the
 * SAME logic, the same discipline as `CorpusCoverageService`/
 * `DeflectionAnalytics` in earlier steps.
 */
class QualitySamplingService
{
    public function __construct(private readonly DeflectionAnalytics $analytics)
    {
    }

    /**
     * §6.2 — the stratified, seeded draw for one calendar month.
     *
     * Population: every `message_traces` row for an answered turn
     * (`floor_decision.outcome='answer'`) in the month (reuses
     * `DeflectionAnalytics::liveTurns()` — the SAME turn definition as the
     * deflection screen, not a second copy). Strata: `(path, territory_id)`.
     * Allocation: `max(1, round(N × stratum_size / total_size))` per
     * non-empty stratum. Draw: seeded (`--seed`, default `crc32($month)`),
     * so the same month + same seed reproduces the identical `message_id`
     * set (`Sprint8QualitySampleStratificationTest` proves this).
     *
     * Idempotent per month WITHOUT destroying human review work: existing
     * rows for `$month` with a verdict already recorded are left untouched;
     * only unreviewed rows (verdict IS NULL) for this month are replaced.
     *
     * @return array{drawn:int,strata:int,seed:int,total_population:int}
     */
    public function draw(string $month, int $n, ?int $seed = null): array
    {
        $seed ??= (int) crc32($month);
        [$start, $end] = $this->monthBounds($month);

        $population = $this->analytics->liveTurns($start, $end)->where('outcome', 'answer')->values();

        if ($population->isEmpty()) {
            $this->replaceUnreviewedForMonth($month, []);

            return ['drawn' => 0, 'strata' => 0, 'seed' => $seed, 'total_population' => 0];
        }

        $strataKey = fn (array $t) => ($t['path'] ?? 'unknown').'|'.($t['territory_id'] ?? 'null');
        $byStratum = $population->groupBy($strataKey)->sortKeys(); // deterministic stratum ORDER, independent of DB row order.

        $total = $population->count();
        $rows = [];

        // One RNG stream, seeded once, consumed in a fixed (sorted-stratum,
        // sorted-member) order — this is what makes "same month + same seed"
        // reproduce the identical draw regardless of incidental DB ordering.
        mt_srand($seed);

        foreach ($byStratum as $key => $members) {
            [$path, $territoryRaw] = explode('|', $key, 2);
            $territoryId = $territoryRaw === 'null' ? null : (int) $territoryRaw;

            $count = max(1, (int) round($n * $members->count() / $total));
            $count = min($count, $members->count());

            $sorted = $members->sortBy('message_id')->values();
            $picked = $this->seededSample($sorted, $count);

            foreach ($picked as $turn) {
                $rows[] = [
                    'message_id' => $turn['message_id'],
                    'stratum_path' => $path === 'unknown' ? null : $path,
                    'stratum_territory_id' => $territoryId,
                ];
            }
        }

        $this->replaceUnreviewedForMonth($month, $rows, $seed);

        return [
            'drawn' => count($rows),
            'strata' => $byStratum->count(),
            'seed' => $seed,
            'total_population' => $total,
        ];
    }

    /** @return array{0:Carbon,1:Carbon} */
    private function monthBounds(string $month): array
    {
        $start = Carbon::createFromFormat('Y-m', $month)->startOfMonth();

        return [$start, $start->copy()->addMonth()];
    }

    /**
     * A deterministic, seeded, without-replacement sample of `$n` items from
     * an already-sorted collection — Fisher-Yates over a fixed-order index
     * list, consuming the (already `mt_srand`-seeded) global RNG stream.
     */
    private function seededSample(Collection $sorted, int $n): array
    {
        $indexes = range(0, $sorted->count() - 1);
        for ($i = count($indexes) - 1; $i > 0; $i--) {
            $j = mt_rand(0, $i);
            [$indexes[$i], $indexes[$j]] = [$indexes[$j], $indexes[$i]];
        }

        $picked = array_slice($indexes, 0, $n);
        sort($picked); // stable member ordering within the stratum, cosmetic only.

        return array_map(fn ($i) => $sorted[$i], $picked);
    }

    /** @param  list<array{message_id:int,stratum_path:?string,stratum_territory_id:?int}>  $rows */
    private function replaceUnreviewedForMonth(string $month, array $rows, ?int $seed = null): void
    {
        DB::transaction(function () use ($month, $rows, $seed) {
            QualitySample::where('sampled_for_month', $month)->whereNull('verdict')->delete();

            foreach ($rows as $row) {
                QualitySample::create([
                    'message_id' => $row['message_id'],
                    'sampled_for_month' => $month,
                    'seed' => $seed,
                    'stratum_path' => $row['stratum_path'],
                    'stratum_territory_id' => $row['stratum_territory_id'],
                ]);
            }
        });
    }

    /**
     * §6.3's "reviewer ≠ the agent who handled any related card" rule — a
     * small, explicit guard (not structural, per the plan's own framing of
     * this as a low-volume edge case). Returns true iff `$admin` is barred
     * from reviewing `$sample` because they are `assigned_to` on an
     * escalation card whose `source_message_id`/session matches this
     * sample's turn.
     */
    public function reviewerIsBarred(QualitySample $sample, Admin $admin): bool
    {
        $message = $sample->message;
        if ($message === null) {
            return false;
        }

        return EscalationCard::where('chat_session_id', $message->session_id)
            ->where('assigned_to', $admin->id)
            ->exists();
    }

    /**
     * §6.3/§6.4 — the ONE review decision for a sampled turn. On
     * `verdict='wrong'`, opens an escalation card exactly like every other
     * card-creation path (reason=`quality_sample_wrong`), via
     * `EscalationExplainer::explain()` — no parallel explanation logic.
     *
     * @throws \RuntimeException if `$admin` is barred (§6.3's reviewer rule)
     */
    public function recordVerdict(QualitySample $sample, Admin $admin, string $verdict, ?string $failureKind, ?string $note): QualitySample
    {
        if ($this->reviewerIsBarred($sample, $admin)) {
            throw new \RuntimeException('This reviewer is assigned to a related escalation card and cannot review this sample (plan.md §6.3).');
        }

        $sample->reviewed_by = $admin->id;
        $sample->verdict = $verdict;
        $sample->failure_kind = $verdict === 'correct' ? null : $failureKind;
        $sample->note = $note;
        $sample->reviewed_at = now();

        if ($verdict === 'wrong') {
            $sample->escalation_card_id = $this->openFixCard($sample, $failureKind ?? 'other')->id;
        }

        $sample->save();

        return $sample;
    }

    private function openFixCard(QualitySample $sample, string $failureKind): EscalationCard
    {
        /** @var ChatMessage $assistantMessage */
        $assistantMessage = $sample->message;
        $session = $assistantMessage->session;
        $employee = $session?->employee;

        // The card's source_message_id follows every other creation path's
        // convention (ChatService.php:1571) — the USER turn, not the
        // assistant reply — so it resolves the same way in the board/drawer.
        $userMessage = $session?->messages()
            ->where('role', 'user')
            ->where('id', '<', $assistantMessage->id)
            ->orderByDesc('id')
            ->first();

        $trace = [
            'quality_sample' => [
                'failure_kind' => $failureKind,
                'employee_uuid' => $employee?->uuid,
                'fact_uuid' => null,
            ],
        ];
        $explanation = EscalationExplainer::explain('quality_sample_wrong', $trace);

        return EscalationCard::create([
            'chat_session_id' => $session?->id,
            'source_message_id' => $userMessage?->id ?? $assistantMessage->id,
            'employee_id' => $employee?->id,
            'reason' => 'quality_sample_wrong',
            'status' => 'new',
            'explanation_facts' => $explanation,
            'fix_action' => $explanation['fix_action'],
            'fix_surface' => $explanation['fix_surface'],
            'fix_link' => $explanation['fix_link'],
        ]);
    }

    /**
     * §6.5 — the monthly trend, a live `group by` over `quality_samples`
     * directly (no separate rollup table: a month's samples are a small,
     * fixed-size, already-materialized set, per the plan's own reasoning).
     *
     * @return list<array{month:string,verdict:?string,stratum_path:?string,stratum_territory_id:?int,count:int}>
     */
    public function monthlyTrend(): array
    {
        $rows = DB::table('quality_samples')
            ->selectRaw('sampled_for_month as month, verdict, stratum_path, stratum_territory_id, count(*) as count')
            ->groupBy('sampled_for_month', 'verdict', 'stratum_path', 'stratum_territory_id')
            ->orderBy('sampled_for_month')
            ->get();

        return $rows->map(fn ($r) => [
            'month' => $r->month,
            'verdict' => $r->verdict,
            'stratum_path' => $r->stratum_path,
            'stratum_territory_id' => $r->stratum_territory_id,
            'count' => (int) $r->count,
        ])->all();
    }
}
