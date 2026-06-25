<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\DocumentReviewTask;
use App\Models\TagEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The expiry review queue + the lineage write-side (Sprint 7a, §C).
 *
 * The expiry queue lists `document_review_tasks type=expiry` (materialized by
 * `reviews:scan-expiry`). Resolving an expiry task is where a human confirms
 * succession: marking a SAME-CONVENIO newer document as the successor writes
 * `predecessor_document_id` (the Sprint-1 stub, now implemented) — with
 * provenance.
 *
 * Hard rules (roadmap):
 *  - Succession is SCOPE-BASED: a successor must share the predecessor's convenio
 *    (different-convenio docs coexist — retiring one would delete live info for a
 *    population). Cross-convenio succession is rejected (422).
 *  - NEVER auto-retire: the predecessor's retrieval_status flips to `historical`
 *    ONLY on an explicit `retire_predecessor=true` + `confirm_scope_change=true`.
 *    Linking a successor alone never retires anything.
 */
class ReviewQueueController extends Controller
{
    /** The expiry queue: open expiry tasks + same-convenio successor candidates. Read — any admin. */
    public function expiry(Request $request): JsonResponse
    {
        $tasks = DocumentReviewTask::with(['document.convenio', 'document.documentType'])
            ->where('type', 'expiry')
            ->where('status', 'open')
            ->orderBy('due_date')
            ->get();

        $rows = $tasks->map(function (DocumentReviewTask $t) {
            $doc = $t->document;

            // Same-convenio candidate successors (scope-based): other documents in
            // the same convenio, newest validity first. Different-convenio docs are
            // NEVER candidates (they coexist).
            $candidates = collect();
            if ($doc?->convenio_id !== null) {
                $candidates = Document::where('convenio_id', $doc->convenio_id)
                    ->where('id', '!=', $doc->id)
                    ->orderByDesc('validity_start')
                    ->limit(20)
                    ->get(['uuid', 'title', 'validity_start', 'validity_end', 'retrieval_status'])
                    ->map(fn ($c) => [
                        'uuid' => $c->uuid,
                        'title' => $c->title,
                        'validity_start' => $c->validity_start?->toDateString(),
                        'validity_end' => $c->validity_end?->toDateString(),
                        'retrieval_status' => $c->retrieval_status,
                    ]);
            }

            return [
                'task_id' => $t->id,
                'due_date' => $t->due_date?->toDateString(),
                'past' => $t->due_date !== null && $t->due_date->isPast(),
                'document' => $doc ? [
                    'uuid' => $doc->uuid,
                    'title' => $doc->title,
                    'convenio' => $doc->convenio ? ['id' => $doc->convenio->id, 'numero' => $doc->convenio->numero, 'name' => $doc->convenio->name] : null,
                    'validity_start' => $doc->validity_start?->toDateString(),
                    'validity_end' => $doc->validity_end?->toDateString(),
                    'retrieval_status' => $doc->retrieval_status,
                ] : null,
                'is_unscoped' => $doc?->convenio_id === null,
                'successor_candidates' => $candidates->values(),
            ];
        });

        return response()->json(['tasks' => $rows]);
    }

    /**
     * Resolve an expiry task. The human-confirmed succession handoff (gated
     * knowledge.edit).
     *
     * action:
     *  - link_successor : write the SAME-CONVENIO successor's predecessor_document_id
     *    = this (expiring) document. Optionally retire this document (historical)
     *    ONLY with retire_predecessor=true AND confirm_scope_change=true.
     *  - dismiss        : resolve the task (e.g. renewed in place / no action).
     *  - escalate       : open a `conflict` review task for deeper human adjudication.
     */
    public function resolveExpiry(Request $request, int $taskId): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', 'in:link_successor,dismiss,escalate'],
            'successor_uuid' => ['required_if:action,link_successor', 'string'],
            'retire_predecessor' => ['sometimes', 'boolean'],
            'confirm_scope_change' => ['sometimes', 'boolean'],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $task = DocumentReviewTask::where('type', 'expiry')->where('id', $taskId)->firstOrFail();
        if ($task->status !== 'open') {
            return response()->json(['message' => 'This expiry task is already resolved.'], 422);
        }
        $predecessor = $task->document; // the expiring document
        $adminId = $request->user()->id;

        if ($data['action'] === 'dismiss') {
            $this->resolveTask($task, $adminId);

            return response()->json(['status' => 'ok', 'action' => 'dismiss']);
        }

        if ($data['action'] === 'escalate') {
            DB::transaction(function () use ($predecessor, $task, $adminId, $data) {
                DocumentReviewTask::create([
                    'document_id' => $predecessor->id,
                    'type' => 'conflict',
                    'reason' => 'conflict',
                    'raw_unmatched_values' => null,
                    'status' => 'open',
                ]);
                TagEvent::create([
                    'entity_type' => 'document',
                    'entity_id' => $predecessor->id,
                    'facet' => 'retrieval_status',
                    'old_value' => null,
                    'new_value' => null,
                    'source' => 'admin_manual',
                    'actor_id' => $adminId,
                    'confidence' => null,
                    'note' => 'expiry escalated for adjudication'.(! empty($data['note']) ? ': '.$data['note'] : ''),
                ]);
                $this->resolveTask($task, $adminId);
            });

            return response()->json(['status' => 'ok', 'action' => 'escalate']);
        }

        // --- link_successor: the lineage write-side -----------------------------
        $successor = Document::where('uuid', $data['successor_uuid'])->firstOrFail();

        // Scope-based succession: the successor must share the predecessor's
        // convenio. Different-convenio (or unscoped) docs are NEVER succession
        // candidates — they coexist (retiring one deletes live info for a population).
        if ($predecessor->convenio_id === null || $successor->convenio_id === null
            || $successor->convenio_id !== $predecessor->convenio_id) {
            return response()->json([
                'message' => 'Succession is scope-based: a successor must be a newer version of the SAME convenio. Different-convenio documents coexist and are never succession candidates.',
                'scope_based' => true,
            ], 422);
        }
        if ($successor->id === $predecessor->id) {
            return response()->json(['message' => 'A document cannot succeed itself.'], 422);
        }

        $retire = (bool) ($data['retire_predecessor'] ?? false);
        // Retiring the predecessor is scope-affecting (it moves the eligibility
        // window). It is NEVER automatic — it requires the explicit retire flag
        // AND confirm_scope_change.
        if ($retire && ! $request->boolean('confirm_scope_change')) {
            return response()->json([
                'message' => 'Retiring the predecessor changes which employees receive it as an answer. Re-send with retire_predecessor=true and confirm_scope_change=true to apply.',
                'scope_affecting' => true,
            ], 409);
        }

        DB::transaction(function () use ($successor, $predecessor, $retire, $adminId, $task) {
            // Write the stub: the successor names the predecessor it supersedes.
            $successor->predecessor_document_id = $predecessor->id;
            $successor->save();
            TagEvent::create([
                'entity_type' => 'document',
                'entity_id' => $successor->id,
                'facet' => 'predecessor',
                'old_value' => null,
                'new_value' => (string) $predecessor->uuid,
                'source' => 'admin_manual',
                'actor_id' => $adminId,
                'confidence' => null,
                'note' => "succeeds document {$predecessor->uuid} (same convenio)",
            ]);

            // Optional, EXPLICIT retire of the predecessor (never automatic).
            if ($retire && $predecessor->retrieval_status !== 'historical') {
                $old = $predecessor->retrieval_status;
                $predecessor->retrieval_status = 'historical';
                $predecessor->save();
                TagEvent::create([
                    'entity_type' => 'document',
                    'entity_id' => $predecessor->id,
                    'facet' => 'retrieval_status',
                    'old_value' => $old,
                    'new_value' => 'historical',
                    'source' => 'admin_manual',
                    'actor_id' => $adminId,
                    'confidence' => null,
                    'note' => 'retired on confirmed succession',
                ]);
            }

            $this->resolveTask($task, $adminId);
        });

        return response()->json([
            'status' => 'ok',
            'action' => 'link_successor',
            'successor_uuid' => $successor->uuid,
            'predecessor_uuid' => $predecessor->uuid,
            'retired' => $retire,
        ]);
    }

    private function resolveTask(DocumentReviewTask $task, int $adminId): void
    {
        $task->update([
            'status' => 'resolved',
            'resolved_by' => $adminId,
            'resolved_at' => now(),
        ]);
    }
}
