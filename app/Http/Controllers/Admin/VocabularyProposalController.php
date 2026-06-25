<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\VocabularyProposal;
use App\Services\VocabularyProposalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Managed vocabulary growth — propose → approve (Sprint 7a, ADR-0011/0020).
 *
 * READS are open to any admin (browse the proposed-vocabulary list). PROPOSING
 * is gated by knowledge.edit (route middleware). APPROVING / REJECTING is gated
 * by vocabulary.approve (route middleware — super_admin only). A super_admin may
 * propose-and-approve in ONE action via `approve_now` (still two logical steps;
 * a human is the approver — themselves). The AI only ever proposes.
 */
class VocabularyProposalController extends Controller
{
    public function __construct(private VocabularyProposalService $service) {}

    /** The proposed-vocabulary list (default: open proposals). Read — any admin. */
    public function index(Request $request): JsonResponse
    {
        $status = $request->string('status')->toString() ?: 'proposed';

        $proposals = VocabularyProposal::with(['sourceDocument:id,uuid,title', 'proposer:id,full_name', 'approver:id,full_name'])
            ->where('status', $status)
            ->orderByDesc('id')
            ->get()
            ->map(fn (VocabularyProposal $p) => $this->present($p));

        return response()->json([
            'proposals' => $proposals,
            'can_approve' => (bool) $request->user()?->can('vocabulary.approve'),
        ]);
    }

    /**
     * Preview the deterministic variant suggestion for a value (the UI uses this
     * to default to "fold into alias" when something close exists). Read.
     */
    public function suggest(Request $request): JsonResponse
    {
        $data = $request->validate([
            'facet' => ['required', 'in:territory,sector,convenio'],
            'value' => ['required', 'string'],
        ]);

        return response()->json([
            'variant' => $this->service->suggestVariant($data['facet'], $data['value']),
            'threshold' => VocabularyProposalService::VARIANT_THRESHOLD,
        ]);
    }

    /**
     * Propose a vocabulary value (knowledge.edit). When the caller passes
     * `approve_now=true` they must ALSO hold vocabulary.approve (super_admin) —
     * the propose-and-approve shortcut. Otherwise it is a two-step proposal a
     * super_admin later approves.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'facet' => ['required', 'in:territory,sector,convenio'],
            'value' => ['required', 'string', 'max:255'],
            'source_document_uuid' => ['sometimes', 'nullable', 'string'],
            'review_task_id' => ['sometimes', 'nullable', 'integer'],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
            'approve_now' => ['sometimes', 'boolean'],
            'resolution' => ['sometimes', 'in:alias,new_value'],
            'target_id' => ['sometimes', 'nullable', 'integer'],
            'level' => ['sometimes', 'in:national,regional,provincial'],
        ]);

        $user = $request->user();
        $approveNow = (bool) ($data['approve_now'] ?? false);
        if ($approveNow && ! $user->can('vocabulary.approve')) {
            return response()->json([
                'message' => 'Propose-and-approve requires the vocabulary.approve ability (super_admin). You may propose; a super_admin approves.',
            ], 403);
        }

        $sourceDocId = null;
        if (! empty($data['source_document_uuid'])) {
            $sourceDocId = Document::where('uuid', $data['source_document_uuid'])->value('id');
        }

        try {
            $proposal = $this->service->propose($data['facet'], $data['value'], [
                'source_document_id' => $sourceDocId,
                'review_task_id' => $data['review_task_id'] ?? null,
                'proposed_by_source' => 'admin_manual',
                'proposed_by_admin_id' => $user->id,
                'note' => $data['note'] ?? null,
            ]);

            if ($approveNow) {
                $resolution = $data['resolution'] ?? 'alias';
                $result = $this->service->approve($proposal, $resolution, [
                    'target_id' => $data['target_id'] ?? null,
                    'level' => $data['level'] ?? null,
                    'approver_id' => $user->id,
                ]);

                return response()->json(['proposal' => $this->present($proposal->fresh()), 'approved' => $result], 201);
            }

            return response()->json(['proposal' => $this->present($proposal)], 201);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /** Approve a proposal into the vocabulary (vocabulary.approve / super_admin). */
    public function approve(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'resolution' => ['required', 'in:alias,new_value'],
            'target_id' => ['sometimes', 'nullable', 'integer'],
            'level' => ['sometimes', 'in:national,regional,provincial'],
        ]);

        $proposal = VocabularyProposal::findOrFail($id);

        try {
            $result = $this->service->approve($proposal, $data['resolution'], [
                'target_id' => $data['target_id'] ?? null,
                'level' => $data['level'] ?? null,
                'approver_id' => $request->user()->id,
            ]);

            return response()->json(['status' => 'ok', 'result' => $result, 'proposal' => $this->present($proposal->fresh())]);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /** Reject a proposal (vocabulary.approve / super_admin). */
    public function reject(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['note' => ['sometimes', 'nullable', 'string', 'max:500']]);
        $proposal = VocabularyProposal::findOrFail($id);

        try {
            $this->service->reject($proposal, $request->user()->id, $data['note'] ?? null);

            return response()->json(['status' => 'ok', 'proposal' => $this->present($proposal->fresh())]);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /** @return array<string,mixed> */
    private function present(VocabularyProposal $p): array
    {
        return [
            'id' => $p->id,
            'facet' => $p->facet,
            'proposed_value' => $p->proposed_value,
            'variant_of' => $p->variant_of_id ? [
                'type' => $p->variant_of_type,
                'id' => $p->variant_of_id,
                'similarity' => $p->variant_similarity,
            ] : null,
            'resolution' => $p->resolution,
            'status' => $p->status,
            'proposed_by_source' => $p->proposed_by_source,
            'proposed_by' => $p->proposer?->full_name,
            'approved_by' => $p->approver?->full_name,
            'source_document' => $p->sourceDocument ? ['uuid' => $p->sourceDocument->uuid, 'title' => $p->sourceDocument->title] : null,
            'review_task_id' => $p->review_task_id,
            'note' => $p->note,
            'created_at' => $p->created_at?->toDateTimeString(),
        ];
    }
}
