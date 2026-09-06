<?php

namespace App\Services;

use App\Models\ReferenceFact;
use App\Models\TagEvent;
use App\Support\GroupLabel;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Sprint 7d (ADR-0024) — RESOLVE a flagged reference-fact duplicate pair.
 *
 * 7b-2 could only FLAG ("possible version of #N — same scope, different value");
 * its own review called resolution "Sprint 7d". This is that resolution, and it
 * is entirely human-invoked: nothing here runs on a schedule, and nothing picks a
 * winner on its own.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * THE RULE THAT SHAPES ALL OF THIS: A SUPERSEDE CLOSES A VALIDITY WINDOW. IT
 * NEVER DELETES, NEVER MERGES, NEVER DEMOTES.
 *
 * The older fact keeps its row, keeps `verified`, and keeps its value — its
 * `validity_end` is simply set to the day before the newer fact starts. A
 * question dated inside the old window must still get the OLD answer; that is
 * what version history IS. Deleting or merging would make the system silently
 * wrong about the past, which is worse than the ambiguity it was fixing.
 * ────────────────────────────────────────────────────────────────────────────
 *
 * HOW THIS REACHES THE ANSWER: through corrected DATA, and nothing else. No line
 * of `ReferenceFactAnswerService`, `ReferenceFactRouter` or `ChatService` changes
 * in 7d. Today two verified facts with the same validity and differing values
 * make `selectByValidity` return `ambiguous_conflict` → escalate. After a
 * supersede, the older fact falls out of the present-day validity predicate, that
 * function sees ONE candidate, and returns `single`. The escalation stops because
 * the data was fixed — not because a branch was added to the answer loop.
 *
 * THE DIRECTION IS NEVER GUESSED. `supersede` requires the caller to name which
 * fact is newer, and validates that the validity dates support the claim (422
 * otherwise). `validity_start` may be null on either side, and `created_at` /
 * `id` order says nothing about which VERSION is newer — a 2024 document can be
 * ingested after a 2026 one.
 */
class FactResolutionService
{
    public const SUPERSEDE = 'supersede';

    public const COEXIST = 'coexist';

    public const REJECT = 'reject';

    /**
     * Supersede: `$newer` closes `$older`'s validity window.
     *
     * @return array<string,mixed>
     *
     * @throws RuntimeException with a machine-readable code (mapped to 422)
     */
    public function supersede(ReferenceFact $newer, ReferenceFact $older, int $adminId, ?string $note = null): array
    {
        if ($newer->id === $older->id) {
            throw new RuntimeException('same_fact');
        }
        if ($newer->convenio_id !== $older->convenio_id || $newer->topic_id !== $older->topic_id) {
            throw new RuntimeException('scope_mismatch');
        }
        if ($newer->validity_start === null) {
            // Without a start date there is no boundary to close the old window at.
            throw new RuntimeException('newer_has_no_validity_start');
        }
        if ($older->validity_start !== null && $newer->validity_start->lessThanOrEqualTo($older->validity_start)) {
            // The claimed direction contradicts the dates. Refuse rather than pick.
            throw new RuntimeException('newer_does_not_start_after_older');
        }

        $boundary = $newer->validity_start->copy()->subDay();

        return DB::transaction(function () use ($newer, $older, $adminId, $note, $boundary) {
            $previousEnd = $older->validity_end?->toDateString();

            // Closing may only ever SHORTEN the old window. If it already ends at or
            // before the boundary, leave it — this keeps the operation idempotent and
            // makes it impossible for a supersede to EXTEND a fact's reach.
            if ($older->validity_end === null || $older->validity_end->greaterThan($boundary)) {
                $older->validity_end = $boundary;
                TagEvent::create([
                    'entity_type' => 'reference_fact',
                    'entity_id' => $older->id,
                    'facet' => 'validity_end',
                    'old_value' => $previousEnd,
                    'new_value' => $boundary->toDateString(),
                    'source' => 'admin_manual',
                    'actor_id' => $adminId,
                    'confidence' => null,
                    'note' => "validity closed by supersede: fact #{$newer->id} starts {$newer->validity_start->toDateString()}"
                        .' — the older value remains VERIFIED and answerable for its own window (nothing deleted)',
                ]);
            }

            $older->superseded_by_id = $newer->id;
            $older->resolution = 'superseded';
            $older->resolved_by = $adminId;
            $older->resolved_at = now();
            // `status` is deliberately UNTOUCHED: the old fact stays `verified`.
            $older->save();

            $newer->resolution = 'supersedes';
            $newer->resolved_by = $adminId;
            $newer->resolved_at = now();
            // `duplicate_of_id` is RETAINED — the flag is resolved, not erased; it is
            // now the lineage link a human can follow backwards.
            $newer->save();

            $this->logResolution($older, 'superseded', $adminId, $note, "superseded by fact #{$newer->id}");
            $this->logResolution($newer, 'supersedes', $adminId, $note, "supersedes fact #{$older->id}");

            return [
                'action' => self::SUPERSEDE,
                'newer_id' => $newer->id,
                'older_id' => $older->id,
                'older_validity_end' => $older->fresh()->validity_end?->toDateString(),
                'older_status' => $older->fresh()->status,
                'deleted' => 0, // stated explicitly: a supersede deletes nothing
            ];
        });
    }

    /**
     * Coexist: the two facts genuinely both apply (different sub-scopes the group
     * labels express imperfectly). Validity is UNTOUCHED — this records a
     * judgement, it does not change what either fact says.
     *
     * Note the honest consequence: if both are `verified` with the same validity
     * and differing values, the 7c answer rule will still escalate a question that
     * matches both. That is correct — they really are ambiguous for that question —
     * and it is why `coexist` also leaves a note for the human who meets it later.
     *
     * @return array<string,mixed>
     */
    public function coexist(ReferenceFact $a, ReferenceFact $b, int $adminId, ?string $note = null): array
    {
        return DB::transaction(function () use ($a, $b, $adminId, $note) {
            foreach ([$a, $b] as $fact) {
                $fact->resolution = 'coexists';
                $fact->resolved_by = $adminId;
                $fact->resolved_at = now();
                $fact->save();
                $this->logResolution($fact, 'coexists', $adminId, $note,
                    'both facts apply (different sub-scopes) — validity untouched, pair left the duplicate queue');
            }

            return ['action' => self::COEXIST, 'ids' => [$a->id, $b->id], 'validity_changed' => false];
        });
    }

    /**
     * Reject: the duplicate flag was wrong — these are not versions of each other.
     *
     * Reuses the EXISTING `rejected` status semantics for an unverified AI
     * proposal, and refuses to reject a `verified` fact (the same guard
     * `ReferenceFactController::reject` applies). When the flagged fact is
     * verified, only the FLAG is cleared, never the fact.
     *
     * @return array<string,mixed>
     */
    public function rejectDuplicate(ReferenceFact $fact, int $adminId, ?string $note = null): array
    {
        return DB::transaction(function () use ($fact, $adminId, $note) {
            $statusChanged = false;
            if ($fact->status === 'needs_review') {
                $fact->status = 'rejected';
                $statusChanged = true;
            }
            $fact->resolution = 'rejected_duplicate';
            $fact->resolved_by = $adminId;
            $fact->resolved_at = now();
            $fact->save();

            $this->logResolution($fact, 'rejected_duplicate', $adminId, $note, $statusChanged
                ? 'duplicate flag rejected; the unverified proposal was rejected too (kept for audit, never deleted)'
                : 'duplicate flag rejected; the VERIFIED fact itself is untouched (only the flag is resolved)');

            return ['action' => self::REJECT, 'id' => $fact->id, 'fact_status' => $fact->status];
        });
    }

    /**
     * The EXTENDED duplicate pass (Sprint 7d) — deterministic group-digit-token
     * overlap over EXISTING facts, not only at segmentation time.
     *
     * No embeddings and no threshold: the documented miss is a tokenisation
     * problem, not a meaning problem ("Grupos 1 y 2" vs "Grupo 2"), so a token
     * comparison closes it exactly, costs nothing, and is testable on the real
     * strings. See App\Support\GroupLabel.
     *
     * FLAG ONLY. It sets `duplicate_of_id` + `uncertainty` and writes a
     * `tag_events` row. It never links-and-resolves, never merges, never retires,
     * and never picks a winner.
     *
     * @return array<string,mixed>
     */
    public function scanForOverlappingGroupDuplicates(?int $convenioId = null, bool $dryRun = false): array
    {
        $facts = ReferenceFact::query()
            ->where('status', '!=', 'rejected')
            ->whereNull('resolution')          // never re-flag a pair a human resolved
            ->whereNotNull('topic_id')          // the pass is scoped by (convenio, topic)
            ->whereNotNull('convenio_id')
            ->when($convenioId !== null, fn ($q) => $q->where('convenio_id', $convenioId))
            ->orderBy('id')
            ->get();

        $flagged = [];
        $byScope = $facts->groupBy(fn (ReferenceFact $f) => $f->convenio_id.':'.$f->topic_id);

        foreach ($byScope as $group) {
            // Compare newest-first against older facts, so the flag points from the
            // newer fact at the one it may be a version of — the same direction 7b-2's
            // exact-key detector uses.
            $ordered = $group->sortByDesc('id')->values();

            foreach ($ordered as $i => $fact) {
                if ($fact->duplicate_of_id !== null) {
                    continue; // the exact-key detector already flagged it; don't clobber
                }

                for ($j = $i + 1; $j < $ordered->count(); $j++) {
                    $other = $ordered[$j];

                    if (GroupLabel::relate($fact->group_label, $other->group_label) !== GroupLabel::OVERLAP) {
                        continue;
                    }
                    if (trim((string) $fact->value) === trim((string) $other->value)) {
                        continue; // same value → not a version, just two ways of saying it
                    }

                    $reason = GroupLabel::describeOverlap($fact->group_label, $other->group_label);
                    $flagged[] = [
                        'fact_id' => $fact->id,
                        'duplicate_of_id' => $other->id,
                        'group_a' => $fact->group_label,
                        'group_b' => $other->group_label,
                        'value_a' => $fact->value,
                        'value_b' => $other->value,
                        'reason' => $reason,
                    ];

                    if (! $dryRun) {
                        DB::transaction(function () use ($fact, $other, $reason) {
                            $fact->duplicate_of_id = $other->id;
                            if ($fact->uncertainty === null) {
                                $fact->uncertainty = [
                                    'field' => 'version',
                                    'reason' => "posible versión de #{$other->id} ({$reason})",
                                ];
                            }
                            $fact->save();

                            TagEvent::create([
                                'entity_type' => 'reference_fact',
                                'entity_id' => $fact->id,
                                'facet' => 'duplicate',
                                'old_value' => null,
                                'new_value' => (string) $other->id,
                                // `ai_agent`: the flag is a machine's inference, and the
                                // provenance rule is that a machine's claim is marked as one
                                // until a human resolves it (ADR-0020).
                                'source' => 'ai_agent',
                                'actor_id' => null,
                                'confidence' => null,
                                // The note names the PASS, so the two detectors stay
                                // distinguishable in the audit trail with no new column.
                                'note' => "semantic/scope duplicate pass (group-token overlap): {$reason}",
                            ]);
                        });
                    }

                    break; // one flag per fact — it points at the nearest older version
                }
            }
        }

        return ['scanned' => $facts->count(), 'flagged' => count($flagged), 'pairs' => $flagged, 'dry_run' => $dryRun];
    }

    private function logResolution(ReferenceFact $fact, string $resolution, int $adminId, ?string $note, string $detail): void
    {
        TagEvent::create([
            'entity_type' => 'reference_fact',
            'entity_id' => $fact->id,
            'facet' => 'resolution',
            'old_value' => null,
            'new_value' => $resolution,
            'source' => 'admin_manual',
            'actor_id' => $adminId,
            'confidence' => null,
            'note' => $note !== null && trim($note) !== '' ? "{$detail} — {$note}" : $detail,
        ]);
    }
}
