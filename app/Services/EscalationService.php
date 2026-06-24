<?php

namespace App\Services;

use App\Models\Admin;
use App\Models\ChatMessage;
use App\Models\Document;
use App\Models\DocumentReviewTask;
use App\Models\DocumentTopic;
use App\Models\DocumentType;
use App\Models\EscalationCard;
use App\Models\EscalationEvent;
use App\Models\EscalationResolution;
use App\Models\TagEvent;
use App\Models\Topic;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The Sprint-4 escalation-board write surface: assign/move (legal transitions),
 * the human reply into the employee's chat, and resolve → Save-as-knowledge.
 * hr-backend owns ALL writes (ADR-0007); every move/reply/resolution/publish is
 * audited to `escalation_events`. The no-override rule is ENFORCED at publish
 * (block/re-escalate), never advisory.
 */
class EscalationService
{
    /** Legal status transitions (server-enforced). resolved/resolved_at is set by resolve(). */
    private const TRANSITIONS = [
        'new' => ['assigned', 'in_progress', 'closed'],
        'assigned' => ['in_progress', 'new', 'closed'],
        'in_progress' => ['resolved', 'assigned', 'closed'],
        'resolved' => ['closed', 'in_progress'],
        'closed' => ['in_progress'],
    ];

    public function __construct(private readonly RulingPublisher $publisher) {}

    /**
     * Assign and/or move a card. Either field may be provided. Returns the fresh
     * card. Throws RuntimeException('illegal_transition'|'invalid_assignee') on a
     * bad request (the controller maps it to 422).
     */
    public function update(EscalationCard $card, ?string $status, mixed $assignedTo, Admin $actor): EscalationCard
    {
        return DB::transaction(function () use ($card, $status, $assignedTo, $actor) {
            // Assignment first (assigning a `new` card implicitly advances it to
            // `assigned` unless the caller also moves it further).
            if ($assignedTo !== false) { // false = "not provided"
                $newAssignee = $assignedTo === null ? null : (int) $assignedTo;
                if ($newAssignee !== null && Admin::find($newAssignee) === null) {
                    throw new RuntimeException('invalid_assignee');
                }
                if ($newAssignee !== $card->assigned_to) {
                    $old = $card->assigned_to;
                    $card->assigned_to = $newAssignee;
                    if ($newAssignee !== null && $card->status === 'new') {
                        $card->status = 'assigned';
                    }
                    $card->save();
                    $this->log($card, 'assigned', (string) $old, (string) $newAssignee, $actor, 'assignee changed');
                }
            }

            if ($status !== null && $status !== $card->status) {
                if (! in_array($status, self::TRANSITIONS[$card->status] ?? [], true)) {
                    throw new RuntimeException('illegal_transition');
                }
                $old = $card->status;
                $card->status = $status;
                // resolved_at is owned by resolve(); a manual move to resolved
                // still stamps it (and clears on a reopen) so it stays truthful.
                if ($status === 'resolved' && $card->resolved_at === null) {
                    $card->resolved_at = now();
                } elseif ($status !== 'resolved' && $status !== 'closed') {
                    $card->resolved_at = null;
                }
                $card->save();
                $this->log($card, 'status_change', $old, $status, $actor, 'status moved on the board');
            }

            return $card->fresh();
        });
    }

    /**
     * Reply into the employee's chat as a HUMAN (hr_agent) message in the card's
     * session — distinct from a bot answer (no trace, no citations). Audited.
     * Reply and resolve are distinct actions (spec C).
     */
    public function reply(EscalationCard $card, string $content, Admin $actor): ChatMessage
    {
        if ($card->chat_session_id === null) {
            throw new RuntimeException('no_session');
        }

        return DB::transaction(function () use ($card, $content, $actor) {
            $message = ChatMessage::create([
                'session_id' => $card->chat_session_id,
                'role' => 'hr_agent',
                'author_admin_id' => $actor->id,
                'content' => $content,
            ]);

            $card->session?->forceFill(['last_activity_at' => now()])->save();

            $this->log($card, 'replied', null, null, $actor, 'human reply sent to the employee');

            return $message;
        });
    }

    /**
     * Resolve a card, optionally converting the resolution into a published
     * `internal_hr_ruling` (the flywheel).
     *
     * Outcomes:
     *  - ['outcome' => 'resolved', 'card' => …, 'document' => Document|null, 'publish' => array|null]
     *  - ['outcome' => 'publish_blocked', 'conflicts' => list<array>]  (no-override fence)
     *
     * The scope-confirm gate (409) is enforced in the controller (the Sprint-3
     * pattern). This method assumes confirmation already happened for a convert.
     *
     * @return array<string,mixed>
     */
    public function resolve(EscalationCard $card, Admin $actor, string $resolutionText, bool $convert, ?int $topicId): array
    {
        if (! $convert) {
            return DB::transaction(function () use ($card, $actor, $resolutionText) {
                $resolution = EscalationResolution::updateOrCreate(
                    ['card_id' => $card->id],
                    ['resolved_by' => $actor->id, 'resolution_text' => $resolutionText, 'converted_to_document_id' => null],
                );
                $this->markResolved($card, $actor, 'resolved (no conversion)');

                return ['outcome' => 'resolved', 'card' => $card->fresh(), 'document' => null, 'publish' => null, 'resolution' => $resolution];
            });
        }

        // --- Convert → Save as knowledge ---------------------------------------
        $card->loadMissing('employee.convenio');
        $convenioId = $card->employee?->convenio_id;
        if ($convenioId === null) {
            throw new RuntimeException('asker_unscoped'); // employees.convenio_id is NOT NULL — defensive
        }

        $rulingType = DocumentType::where('code', 'internal_hr_ruling')->firstOrFail();
        $topic = $topicId !== null ? Topic::where('id', $topicId)->where('status', 'approved')->first() : null;

        // Reuse-or-create the DRAFT ruling for this card. The resolution row links
        // the card → its ruling document; while a conflict blocks publish the
        // document stays `draft` (spec: "block → keep draft"). Reusing it on a
        // retry avoids piling up orphan drafts each time the fence fires.
        $resolution = EscalationResolution::firstOrNew(['card_id' => $card->id]);
        $existing = $resolution->converted_to_document_id !== null
            ? Document::find($resolution->converted_to_document_id)
            : null;

        $document = DB::transaction(function () use ($card, $actor, $convenioId, $rulingType, $topic, $resolution, $resolutionText, $existing) {
            $doc = $existing;
            if ($doc === null) {
                $doc = new Document([
                    'title' => $this->deriveTitle($card, $topic),
                    'convenio_id' => $convenioId,
                    'document_type_id' => $rulingType->id,
                    'validity_start' => now()->toDateString(),
                    'validity_end' => null,
                    'retrieval_status' => 'draft', // flipped to active ONLY after a clean publish
                    'authority_level' => 'internal_hr_ruling',
                    'language' => 'es',
                    'tagging_status' => 'verified', // the agent owns it (no separate approver)
                    'tagging_confidence' => null,
                    'ingested_at' => now(),
                    'ingested_by' => $actor->id,
                ]);
                $doc->save();

                // Provenance: created-from-escalation (renders in the KC timeline).
                TagEvent::create([
                    'entity_type' => 'document',
                    'entity_id' => $doc->id,
                    'facet' => 'document',
                    'old_value' => null,
                    'new_value' => 'internal_hr_ruling',
                    'source' => 'admin_manual',
                    'actor_id' => $actor->id,
                    'confidence' => null,
                    'note' => "created from escalation #{$card->id} by {$actor->full_name}",
                ]);
            } else {
                $doc->forceFill(['title' => $this->deriveTitle($card, $topic)])->save();
            }

            // Sync the topic (idempotent — the gate-sharpening picker).
            if ($topic !== null && ! DocumentTopic::where('document_id', $doc->id)->where('topic_id', $topic->id)->exists()) {
                DocumentTopic::create([
                    'document_id' => $doc->id,
                    'topic_id' => $topic->id,
                    'source' => 'admin_manual',
                    'confidence' => null,
                    'verified_by' => $actor->id,
                    'verified_at' => now(),
                ]);
                TagEvent::create([
                    'entity_type' => 'document',
                    'entity_id' => $doc->id,
                    'facet' => 'topic',
                    'old_value' => null,
                    'new_value' => $topic->name,
                    'source' => 'admin_manual',
                    'actor_id' => $actor->id,
                    'confidence' => null,
                    'note' => 'topic assigned at publish',
                ]);
            }

            $resolution->fill([
                'resolved_by' => $actor->id,
                'resolution_text' => $resolutionText,
                'converted_to_document_id' => $doc->id,
            ])->save();

            return $doc;
        });

        // The no-override fence (Q-B): a conservative scope+topic block. With a
        // topic → block on an active official_convenio sharing that topic; with
        // NO topic → scope-only block (any active official_convenio in scope) —
        // it OVER-blocks, the safe failure direction (a false block routes to a
        // human; a false "no conflict" is the exact harm we refuse).
        $conflicts = $this->detectConflicts($convenioId, $topicId);
        if ($conflicts->isNotEmpty()) {
            DB::transaction(function () use ($card, $actor, $conflicts, $topicId, $document) {
                // Keep the DRAFT unpublished. Surface the conflict in the document
                // review queue (find-or-create an open `conflict` task on the draft,
                // the same queue ingest conflicts land in — ADR-0011) AND on the
                // card's own audit log.
                DocumentReviewTask::firstOrCreate(
                    ['document_id' => $document->id, 'type' => 'conflict', 'status' => 'open'],
                    [
                        'reason' => 'conflict',
                        'raw_unmatched_values' => $conflicts->map(fn ($d) => ['facet' => 'authority', 'value' => $d->uuid])->all(),
                    ],
                );

                $note = $topicId !== null
                    ? 'official convenio governs this topic in the asker\'s scope — internal ruling cannot override it'
                    : 'no topic assigned — scope-only conflict fence blocked publish (an official convenio is active in scope)';
                $this->log($card, 'publish_blocked', null, $conflicts->pluck('uuid')->implode(','), $actor, $note);

                // Route to a human: the card returns to in_progress (NOT resolved).
                if ($card->status !== 'in_progress') {
                    $old = $card->status;
                    $card->status = 'in_progress';
                    $card->resolved_at = null;
                    $card->save();
                    $this->log($card, 'status_change', $old, 'in_progress', $actor, 're-opened: publish blocked by no-override fence');
                }
            });

            return ['outcome' => 'publish_blocked', 'conflicts' => $conflicts->map(fn ($d) => [
                'uuid' => $d->uuid,
                'title' => $d->title,
            ])->all()];
        }

        // No conflict → render → S3 → /extract → /embed (hr-ai untouched). Outside
        // the DB transaction: it makes network + S3 calls. On success → flip the
        // draft to active and resolve any open conflict task from a prior block.
        $publish = $this->publisher->publish($document, $resolutionText);

        $result = DB::transaction(function () use ($card, $actor, $document, $resolution, $publish) {
            $document->forceFill(['retrieval_status' => 'active'])->save();
            TagEvent::create([
                'entity_type' => 'document',
                'entity_id' => $document->id,
                'facet' => 'retrieval_status',
                'old_value' => 'draft',
                'new_value' => 'active',
                'source' => 'admin_manual',
                'actor_id' => $actor->id,
                'confidence' => null,
                'note' => 'published (scope-confirmed, no conflict) — '.($publish['chunks_written'] ?? 0).' chunks embedded',
            ]);

            DocumentReviewTask::where('document_id', $document->id)
                ->where('type', 'conflict')
                ->where('status', 'open')
                ->update(['status' => 'resolved', 'resolved_by' => $actor->id, 'resolved_at' => now()]);

            $this->log($card, 'converted', null, $document->uuid, $actor, 'resolution published as internal_hr_ruling');
            $this->markResolved($card, $actor, 'resolved (converted to knowledge)');

            return ['outcome' => 'resolved', 'card' => $card->fresh(), 'document' => $document->fresh(), 'publish' => $publish, 'resolution' => $resolution->fresh()];
        });

        return $result;
    }

    /**
     * The no-override conflict query (Q-B). Active official_convenio docs in the
     * asker's convenio scope; narrowed to the assigned topic when present.
     *
     * @return Collection<int, Document>
     */
    private function detectConflicts(int $convenioId, ?int $topicId)
    {
        return Document::query()
            ->where('convenio_id', $convenioId)
            ->where('authority_level', 'official_convenio')
            ->where('retrieval_status', 'active')
            ->when(
                $topicId !== null,
                fn ($q) => $q->whereHas('topics', fn ($t) => $t->where('topics.id', $topicId)),
            )
            ->get(['id', 'uuid', 'title']);
    }

    private function markResolved(EscalationCard $card, Admin $actor, string $note): void
    {
        $old = $card->status;
        if ($card->status !== 'resolved') {
            $card->status = 'resolved';
        }
        $card->resolved_at = $card->resolved_at ?? now();
        $card->save();
        if ($old !== 'resolved') {
            $this->log($card, 'status_change', $old, 'resolved', $actor, $note);
        }
        $this->log($card, 'resolved', null, null, $actor, $note);
    }

    private function deriveTitle(EscalationCard $card, ?Topic $topic): string
    {
        $topicPart = $topic !== null ? " — {$topic->name}" : '';

        return "Resolución interna RR. HH.{$topicPart} (escalación #{$card->id})";
    }

    /** Append an immutable audit row to the card's activity log. */
    private function log(EscalationCard $card, string $type, ?string $old, ?string $new, Admin $actor, ?string $note): void
    {
        EscalationEvent::create([
            'escalation_card_id' => $card->id,
            'type' => $type,
            'old_value' => $old,
            'new_value' => $new,
            'actor_id' => $actor->id,
            'note' => $note,
        ]);
    }
}
