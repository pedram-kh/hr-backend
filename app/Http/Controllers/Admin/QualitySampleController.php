<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\QualitySample;
use App\Services\ConversationAccessLogger;
use App\Services\ConversationPresenter;
use App\Support\QualitySamplingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Sprint 8, Step 6/7 (plan.md §6.3, §9) — the Calidad review-queue tab's API.
 * READS are open to any admin who can see Review (the tab itself is not
 * hidden — mirrors every other `ReviewQueuePage` tab's read-open posture);
 * the ONE decision-writing route (`review`) is gated by `escalation.work`
 * (§6.5 — "sampling review IS escalation-adjacent human work, not a new
 * ability to invent").
 */
class QualitySampleController extends Controller
{
    public function __construct(
        private readonly QualitySamplingService $service,
        private readonly ConversationAccessLogger $accessLogger,
        private readonly ConversationPresenter $presenter,
    ) {}

    /** Paginated list, filterable by month/verdict — the Calidad tab's table. */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'month' => ['nullable', 'string'],
            'verdict' => ['nullable', 'string'],
            'unreviewed' => ['nullable', 'boolean'],
        ]);

        $query = QualitySample::query()
            ->with(['message:id,session_id,content', 'reviewedBy:id,full_name', 'stratumTerritory:id,name', 'escalationCard:id,uuid'])
            ->orderByDesc('id');

        if (! empty($data['month'])) {
            $query->where('sampled_for_month', $data['month']);
        }
        if (! empty($data['verdict'])) {
            $query->where('verdict', $data['verdict']);
        }
        if (! empty($data['unreviewed'])) {
            $query->whereNull('verdict');
        }

        $samples = $query->paginate(50);

        return response()->json(['samples' => $samples]);
    }

    /**
     * Detail: the sampled turn's full conversation (citations + trace),
     * reusing `ConversationPresenter` — the SAME rendering logic
     * `EscalationCardDrawer` uses for a card (plan.md §6.3). Opening this
     * writes `conversation_access_log` (ADR-0018) with the
     * `quality_sample:<uuid>` context marker — a genuinely new access path,
     * not covered by either of `ConversationAccessLogger`'s pre-Sprint-8 call
     * sites.
     */
    public function show(string $uuid, Request $request): JsonResponse
    {
        /** @var QualitySample $sample */
        $sample = QualitySample::where('uuid', $uuid)->with(['message', 'reviewedBy:id,full_name', 'stratumTerritory:id,name', 'escalationCard:id,uuid'])->firstOrFail();

        /** @var Admin $actor */
        $actor = $request->user();
        $session = $sample->message?->session;

        $conversation = [];
        if ($session !== null) {
            $this->accessLogger->logView($actor, $session, "quality_sample:{$sample->uuid}");
            $conversation = $this->presenter->present($session, ConversationPresenter::AUDIENCE_ADMIN);
        }

        return response()->json([
            'sample' => $sample,
            'conversation' => $conversation,
            'reviewer_barred' => $this->service->reviewerIsBarred($sample, $actor),
        ]);
    }

    /** §6.3/§6.4 — the one review decision. Gated by `escalation.work` in the route group. */
    public function review(string $uuid, Request $request): JsonResponse
    {
        $sample = QualitySample::where('uuid', $uuid)->firstOrFail();

        $data = $request->validate([
            'verdict' => ['required', 'string', 'in:correct,partially,wrong'],
            'failure_kind' => ['nullable', 'string', 'in:wrong_scope,wrong_figure,stale_document,unclear,other'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        if ($data['verdict'] !== 'correct' && empty($data['failure_kind'])) {
            return response()->json(['message' => 'failure_kind is required when verdict is not "correct".'], 422);
        }

        /** @var Admin $actor */
        $actor = $request->user();

        try {
            $sample = $this->service->recordVerdict($sample, $actor, $data['verdict'], $data['failure_kind'] ?? null, $data['note'] ?? null);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $sample->load(['reviewedBy:id,full_name', 'escalationCard:id,uuid']);

        return response()->json(['sample' => $sample]);
    }

    /** §6.5 — the monthly trend (a live group-by, no separate rollup table). */
    public function trend(): JsonResponse
    {
        return response()->json(['trend' => $this->service->monthlyTrend()]);
    }
}
