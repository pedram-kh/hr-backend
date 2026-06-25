<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Services\ConversationAccessLogger;
use App\Services\ConversationPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The gated full-History browser + search (Sprint 5, ADR-0018). Browse ALL
 * employees' conversations, search across them, open any conversation. The whole
 * controller sits behind `ability:history.view_all` (super_admin + auditor only)
 * in the route group — the SERVER is the boundary; hitting these directly without
 * the ability 403s. An hr_agent (no history.view_all) cannot reach any of these;
 * they keep ONLY their card-scoped view (EscalationController, unchanged).
 *
 * EVERY conversation access writes conversation_access_log — including a
 * super_admin's read. Read-only: any ACTION (reply/resolve) routes through the
 * escalation.work-gated escalation endpoints, which auditor lacks.
 */
class HistoryController extends Controller
{
    public function __construct(
        private readonly ConversationPresenter $presenter,
        private readonly ConversationAccessLogger $accessLog,
    ) {}

    /**
     * List/search ALL sessions across employees. Filters: employee_uuid,
     * convenio_id, territory_id, date range (last_activity_at primary, Q10),
     * reason, outcome (answered | escalated).
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employee_uuid' => ['nullable', 'string'],
            'convenio_id' => ['nullable', 'integer'],
            'territory_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'reason' => ['nullable', 'string'],
            'outcome' => ['nullable', 'in:answered,escalated'],
        ]);

        $query = ChatSession::query()
            ->with(['employee:id,uuid,full_name,convenio_id,territory_id', 'employee.convenio:id,numero,name', 'employee.territory:id,code,name'])
            ->withCount('messages')
            ->select('chat_sessions.*')
            ->selectRaw('exists(select 1 from escalation_cards ec where ec.chat_session_id = chat_sessions.id) as is_escalated')
            ->selectRaw('(select reason from escalation_cards ec where ec.chat_session_id = chat_sessions.id order by id desc limit 1) as escalation_reason');

        if (! empty($data['employee_uuid'])) {
            $query->whereHas('employee', fn ($e) => $e->where('uuid', $data['employee_uuid']));
        }
        if (! empty($data['convenio_id'])) {
            $query->whereHas('employee', fn ($e) => $e->where('convenio_id', $data['convenio_id']));
        }
        if (! empty($data['territory_id'])) {
            $query->whereHas('employee', fn ($e) => $e->where('territory_id', $data['territory_id']));
        }
        if (! empty($data['from'])) {
            $query->whereDate('last_activity_at', '>=', $data['from']);
        }
        if (! empty($data['to'])) {
            $query->whereDate('last_activity_at', '<=', $data['to']);
        }
        if (! empty($data['reason'])) {
            $query->whereExists(fn ($q) => $q->selectRaw('1')->from('escalation_cards as ec')
                ->whereColumn('ec.chat_session_id', 'chat_sessions.id')
                ->where('ec.reason', $data['reason']));
        }
        if (($data['outcome'] ?? null) === 'escalated') {
            $query->whereExists(fn ($q) => $q->selectRaw('1')->from('escalation_cards as ec')
                ->whereColumn('ec.chat_session_id', 'chat_sessions.id'));
        } elseif (($data['outcome'] ?? null) === 'answered') {
            $query->whereNotExists(fn ($q) => $q->selectRaw('1')->from('escalation_cards as ec')
                ->whereColumn('ec.chat_session_id', 'chat_sessions.id'));
        }

        $sessions = $query->orderByDesc('last_activity_at')->orderByDesc('id')->paginate(50);
        $sessions->getCollection()->transform(fn (ChatSession $s) => $this->listRow($s));

        return response()->json($sessions);
    }

    /** One employee's sessions (the "list an employee's sessions" endpoint). */
    public function employee(string $employeeUuid, Request $request): JsonResponse
    {
        $request->merge(['employee_uuid' => $employeeUuid]);

        return $this->index($request);
    }

    /**
     * Open a full conversation (messages + citations + traces, AUDIENCE_ADMIN).
     * Writes a conversation_view access-log row — EVERY read, incl. super_admin.
     */
    public function show(string $sessionUuid, Request $request): JsonResponse
    {
        $session = ChatSession::with(['employee:id,uuid,full_name,convenio_id', 'employee.convenio:id,numero,name'])
            ->where('uuid', $sessionUuid)
            ->firstOrFail();

        /** @var \App\Models\Admin $actor */
        $actor = $request->user();
        $this->accessLog->logView($actor, $session); // accountability — never skipped

        return response()->json([
            'session_uuid' => $session->uuid,
            'employee' => $session->employee !== null ? [
                'uuid' => $session->employee->uuid,
                'full_name' => $session->employee->full_name,
                'convenio' => $session->employee->convenio !== null ? [
                    'numero' => $session->employee->convenio->numero,
                    'name' => $session->employee->convenio->name,
                ] : null,
            ] : null,
            'started_at' => $session->started_at?->toIso8601String(),
            'last_activity_at' => $session->last_activity_at?->toIso8601String(),
            'messages' => $this->presenter->present($session, ConversationPresenter::AUDIENCE_ADMIN),
        ]);
    }

    /**
     * Search across all conversations' message content. Returns matching sessions
     * with a BRIEF match fragment (not enough to read the conversation's
     * substance — opening a result writes the per-employee conversation_view).
     * Writes one history_search access-log row per run (Q7).
     */
    public function search(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:200'],
        ]);
        $q = trim($data['q']);

        /** @var \App\Models\Admin $actor */
        $actor = $request->user();
        $this->accessLog->logSearch($actor, $q);

        $matches = ChatMessage::query()
            ->where('content', 'ILIKE', '%'.$q.'%')
            ->with(['session:id,uuid,employee_id,last_activity_at', 'session.employee:id,uuid,full_name'])
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(fn (ChatMessage $m) => [
                'session_uuid' => $m->session?->uuid,
                'employee' => $m->session?->employee !== null ? [
                    'uuid' => $m->session->employee->uuid,
                    'full_name' => $m->session->employee->full_name,
                ] : null,
                'role' => $m->role,
                'snippet' => $this->snippet((string) $m->content, $q),
                'last_activity_at' => $m->session?->last_activity_at?->toIso8601String(),
            ])
            ->values();

        return response()->json(['query' => $q, 'matches' => $matches]);
    }

    /** @return array<string, mixed> */
    private function listRow(ChatSession $s): array
    {
        return [
            'session_uuid' => $s->uuid,
            'employee' => $s->employee !== null ? [
                'uuid' => $s->employee->uuid,
                'full_name' => $s->employee->full_name,
                'convenio' => $s->employee->convenio !== null
                    ? ['numero' => $s->employee->convenio->numero, 'name' => $s->employee->convenio->name]
                    : null,
                'territory' => $s->employee->territory !== null
                    ? ['code' => $s->employee->territory->code, 'name' => $s->employee->territory->name]
                    : null,
            ] : null,
            'started_at' => $s->started_at?->toIso8601String(),
            'last_activity_at' => $s->last_activity_at?->toIso8601String(),
            'message_count' => $s->messages_count,
            'escalated' => (bool) $s->is_escalated,
            'escalation_reason' => $s->escalation_reason,
        ];
    }

    /**
     * A brief, centred match fragment (≤ ~90 chars) — deliberately short so a
     * search listing reveals a match, not the conversation's substance (Q7).
     */
    private function snippet(string $content, string $query): string
    {
        $content = trim((string) preg_replace('/\s+/', ' ', $content));
        $pos = mb_stripos($content, $query);
        if ($pos === false) {
            return mb_substr($content, 0, 90);
        }
        $start = max(0, $pos - 30);
        $fragment = mb_substr($content, $start, 90);

        return ($start > 0 ? '…' : '').$fragment.($start + 90 < mb_strlen($content) ? '…' : '');
    }
}
