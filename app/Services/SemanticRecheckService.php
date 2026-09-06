<?php

namespace App\Services;

use App\Models\Document;
use App\Models\DocumentReviewTask;
use App\Support\SemanticComparison;
use Illuminate\Support\Facades\DB;

/**
 * The §8.5 REVERSE re-check (Sprint 7d, ADR-0024) — closed at the flag level.
 *
 * THE GAP IT CLOSES. The publish fence runs when a RULING is published: it stops
 * a ruling that contradicts the convenio already in scope. It cannot help in the
 * other direction — a ruling published legitimately (the convenio was silent),
 * and then a new official convenio arrives that DOES speak to the same point.
 * The ruling is now stale, and nothing notices. `architecture.md` §8.5 recorded
 * that boundary; this closes it at the minimum useful bar.
 *
 * WHAT IT DOES: exactly one thing — it FLAGS. A `document_review_tasks` row of
 * type `conflict` on the RULING, carrying the matched chunk ids and scores.
 *
 * WHAT IT DELIBERATELY DOES NOT DO:
 *  - it never changes `retrieval_status` (no demotion, no retirement);
 *  - it never touches retrieval or the precedence re-rank — 2b's convenio-over-
 *    ruling precedence already handles the ANSWER correctly, so a stale ruling
 *    cannot outrank the new convenio in an answer even while it sits unflagged.
 *    That is why flagging is a genuinely sufficient bar here and not a fudge: the
 *    flag exists to get the ruling REWRITTEN, not to protect the answer.
 *
 * It reuses the existing `type = 'conflict'` task value with a `kind`
 * discriminator inside `raw_unmatched_values`, so no enum CHECK is rewritten
 * (7b-2's `reference_facts.status` migration shows what that costs).
 */
class SemanticRecheckService
{
    public function __construct(private readonly SemanticFenceService $fence) {}

    /**
     * Compare a newly-active official convenio against the published rulings in
     * its own scope and flag the ones that overlap.
     *
     * Threshold: the REVIEW band, not the block threshold — a flag into a human
     * queue is cheap and a missed stale ruling is not, so this side is deliberately
     * more sensitive than the publish fence.
     *
     * @return array<string,mixed>
     */
    public function recheck(Document $convenioDoc): array
    {
        if ($convenioDoc->authority_level !== 'official_convenio' || $convenioDoc->retrieval_status !== 'active') {
            return ['skipped' => 'not an active official_convenio'];
        }

        $comparison = $this->fence->compareConvenioToRulings($convenioDoc);

        // A failed/empty comparison flags nothing. Unlike the publish fence there is
        // no action to gate here, so there is nothing to fail closed ON: the honest
        // outcome is "no flag raised", reported so a caller can retry.
        if ($comparison->matches === []) {
            return [
                'convenio_document_id' => $convenioDoc->id,
                'outcome' => $comparison->outcome,
                'reason' => $comparison->reason,
                'flagged' => 0,
                'max_score' => $comparison->maxScore,
            ];
        }

        // Group the matched chunks by the RULING they belong to — one flag per
        // ruling, not one per chunk.
        $byRuling = [];
        foreach ($comparison->matches as $match) {
            if (($match['score'] ?? 0) < $comparison->reviewBand) {
                continue;
            }
            $byRuling[(int) $match['document_id']][] = $match;
        }

        $flagged = [];
        foreach ($byRuling as $rulingId => $matches) {
            $ruling = Document::find($rulingId);
            if ($ruling === null || $ruling->authority_level !== 'internal_hr_ruling') {
                continue; // defensive: the authority band was already filtered in SQL
            }

            DB::transaction(function () use ($ruling, $convenioDoc, $matches, $comparison, &$flagged) {
                $task = DocumentReviewTask::firstOrNew([
                    'document_id' => $ruling->id,
                    'type' => 'conflict',
                    'status' => 'open',
                ]);

                $payload = [
                    // The discriminator: this conflict task came from the reverse
                    // re-check, not from a blocked publish. Both live in the same
                    // queue on purpose — a human resolves them the same way.
                    'kind' => 'semantic_reverse_recheck',
                    'triggered_by_document_id' => $convenioDoc->id,
                    'triggered_by_document_uuid' => (string) $convenioDoc->uuid,
                    'triggered_by_title' => $convenioDoc->title,
                    'review_band' => $comparison->reviewBand,
                    'max_score' => max(array_map(fn ($m) => (float) $m['score'], $matches)),
                    'matches' => array_map(fn ($m) => [
                        'ruling_chunk_id' => $m['chunk_id'] ?? null,
                        'convenio_chunk_excerpt' => $m['probe_excerpt'] ?? null,
                        'ruling_excerpt' => $m['excerpt'] ?? null,
                        'score' => $m['score'] ?? null,
                    ], array_slice($matches, 0, 5)),
                ];

                // Append, never overwrite: an existing task may already carry the
                // authority values a blocked publish wrote, or an earlier re-check.
                $existing = $task->raw_unmatched_values ?? [];
                $task->reason = 'conflict';
                $task->raw_unmatched_values = array_merge(
                    is_array($existing) ? $existing : [],
                    [$payload],
                );
                $task->status = 'open';
                $task->save();

                $flagged[] = ['document_id' => $ruling->id, 'title' => $ruling->title, 'max_score' => $payload['max_score']];
            });
        }

        return [
            'convenio_document_id' => $convenioDoc->id,
            'outcome' => $comparison->outcome,
            'reason' => $comparison->reason,
            'flagged' => count($flagged),
            'rulings' => $flagged,
            'max_score' => $comparison->maxScore,
        ];
    }

    /** @return array<string,mixed> */
    public function comparisonSummary(SemanticComparison $c): array
    {
        return $c->toAudit();
    }
}
