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

    /**
     * The one JSON shape for a quality sample, shared by `index()`/`show()`/
     * `review()` — found live, eyes-on 2026-09-10, two real bugs in the
     * PREVIOUS ad-hoc `Eloquent::toArray()` serialization this replaces:
     *
     * (1) **Revisor always '—'.** `reviewedBy()` is the Eloquent relation
     *     name; when eager-loaded, Laravel's default `toArray()` keys the
     *     loaded relation under its snake_case form — `reviewed_by` — which
     *     COLLIDES with, and silently overwrites, the raw `reviewed_by` FK
     *     column in the same array (`relationsToArray()` is merged in AFTER
     *     `attributesToArray()`). The frontend was reading a third key,
     *     `reviewed_by_admin`, that the backend never sent at all — so
     *     REVISOR read '—' on every reviewed row, an ADR-0018 audit gap (a
     *     verdict with no visible reviewer). Fixed by splitting into two
     *     deliberately distinct, non-colliding keys: `reviewed_by` (the raw
     *     FK, unambiguous) and `reviewer` (the loaded admin, `{id,
     *     full_name}`).
     * (2) **Pregunta showed the answer.** `message_id` always points at the
     *     ASSISTANT turn (§6.1 — the drawn population is answered turns),
     *     so `message.content` is the answer, not the question. Added a
     *     real `question` field, resolved via
     *     `QualitySamplingService::pairedUserMessage()` (the same
     *     nearest-preceding-user-message lookup `openFixCard()` already
     *     used internally — now shared, not re-derived).
     */
    private function presentSample(QualitySample $sample): array
    {
        return [
            'id' => $sample->id,
            'uuid' => $sample->uuid,
            'message_id' => $sample->message_id,
            'sampled_for_month' => $sample->sampled_for_month,
            'seed' => $sample->seed,
            'stratum_path' => $sample->stratum_path,
            'stratum_territory_id' => $sample->stratum_territory_id,
            'stratum_territory' => $sample->stratumTerritory
                ? ['id' => $sample->stratumTerritory->id, 'name' => $sample->stratumTerritory->name]
                : null,
            'reviewed_by' => $sample->reviewed_by,
            'reviewer' => $sample->reviewedBy
                ? ['id' => $sample->reviewedBy->id, 'full_name' => $sample->reviewedBy->full_name]
                : null,
            'verdict' => $sample->verdict,
            'failure_kind' => $sample->failure_kind,
            'note' => $sample->note,
            'reviewed_at' => $sample->reviewed_at,
            'escalation_card_id' => $sample->escalation_card_id,
            'escalation_card' => $sample->escalationCard
                ? ['id' => $sample->escalationCard->id, 'uuid' => $sample->escalationCard->uuid]
                : null,
            'created_at' => $sample->created_at,
            'message' => $sample->message
                ? ['id' => $sample->message->id, 'session_id' => $sample->message->session_id, 'content' => $sample->message->content]
                : null,
            'question' => $this->service->pairedUserMessage($sample)?->content,
        ];
    }

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

        // A page is capped at 50, and a month's whole population is small by
        // construction (§6.5's own reasoning) — resolving each row's paired
        // question is one extra tiny query per row, not a firehose concern.
        $samples = $query->paginate(50);
        $samples->setCollection($samples->getCollection()->map(fn (QualitySample $s) => $this->presentSample($s)));

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
            'sample' => $this->presentSample($sample),
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

        $sample->load(['message:id,session_id,content', 'reviewedBy:id,full_name', 'stratumTerritory:id,name', 'escalationCard:id,uuid']);

        return response()->json(['sample' => $this->presentSample($sample)]);
    }

    /** §6.5 — the monthly trend (a live group-by, no separate rollup table). */
    public function trend(): JsonResponse
    {
        return response()->json(['trend' => $this->service->monthlyTrend()]);
    }
}
