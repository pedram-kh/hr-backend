<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\EscalationCard;
use App\Services\ConversationPresenter;
use App\Services\EscalationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * The Sprint-4 escalation board (spec A–D). READS (index/show) are open to any
 * admin — an auditor browses the board + opens cards + reads the conversation
 * and trace (read-only posture, mirrors Sprint 3). WRITES (assign/move/reply/
 * resolve) are gated by `ability:escalation.work` in the route group, so an
 * auditor cannot act. hr-backend owns all writes; every move is audited.
 *
 * Card-scoped conversation viewing ONLY: the conversation read is keyed strictly
 * to `card.chat_session_id` (never a caller-supplied employee/session param) —
 * that IS the access guard this sprint. The full-history browser + role-scoped
 * access are Sprint 5.
 */
class EscalationController extends Controller
{
    /** The board reason → human label (Spanish admin voice). */
    private const REASON_LABELS = [
        'low_confidence' => 'Baja confianza',
        'off_domain' => 'Fuera de ámbito',
        'sensitive' => 'Tema sensible',
        'legal_medical' => 'Legal / médico',
        'other_employee' => 'Sobre otra persona',
        'salary_coverage_gap' => 'Hueco en tablas salariales',
        'salary_not_in_chat' => 'Salario no disponible',
    ];

    public function __construct(
        private readonly EscalationService $service,
        private readonly ConversationPresenter $presenter,
    ) {}

    /**
     * List cards for the board, filterable by status / reason / assignee /
     * convenio scope. Returns the cards + per-status counts (the columns).
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', 'string'],
            'reason' => ['nullable', 'string'],
            'assigned_to' => ['nullable', 'integer'],
            'convenio_id' => ['nullable', 'integer'],
            'unassigned' => ['nullable', 'boolean'],
        ]);

        $query = EscalationCard::query()
            ->with(['employee:id,uuid,full_name,convenio_id', 'employee.convenio:id,numero,name', 'assignedTo:id,full_name', 'topic:id,name', 'sourceMessage:id,content'])
            ->orderByDesc('id');

        if (! empty($data['status'])) {
            $query->where('status', $data['status']);
        }
        if (! empty($data['reason'])) {
            $query->where('reason', $data['reason']);
        }
        if (array_key_exists('assigned_to', $data) && $data['assigned_to'] !== null) {
            $query->where('assigned_to', $data['assigned_to']);
        }
        if (! empty($data['unassigned'])) {
            $query->whereNull('assigned_to');
        }
        if (array_key_exists('convenio_id', $data) && $data['convenio_id'] !== null) {
            $query->whereHas('employee', fn ($q) => $q->where('convenio_id', $data['convenio_id']));
        }

        $cards = $query->limit(500)->get()->map(fn (EscalationCard $c) => $this->cardSummary($c));

        $counts = EscalationCard::query()
            ->selectRaw('status, count(*) as n')
            ->groupBy('status')
            ->pluck('n', 'status');

        return response()->json([
            'cards' => $cards,
            'counts' => $counts,
            'statuses' => ['new', 'assigned', 'in_progress', 'resolved', 'closed'],
        ]);
    }

    /**
     * Card detail: the card meta + the card-scoped conversation (the attached
     * session's messages) + the escalation trace + the activity log. The
     * conversation is keyed to card.chat_session_id (the access guard).
     */
    public function show(string $uuid): JsonResponse
    {
        $card = $this->find($uuid);
        $card->load(['employee:id,uuid,full_name,convenio_id', 'employee.convenio:id,numero,name', 'assignedTo:id,full_name', 'topic:id,name', 'sourceMessage:id,content', 'resolution', 'events.actor:id,full_name']);

        $conversation = $card->session !== null
            ? $this->presenter->present($card->session, ConversationPresenter::AUDIENCE_ADMIN)
            : [];

        return response()->json([
            'card' => $this->cardSummary($card),
            'conversation' => $conversation,
            'resolution' => $card->resolution !== null ? [
                'resolution_text' => $card->resolution->resolution_text,
                'converted_to_document_id' => $card->resolution->converted_to_document_id,
                'document' => $card->resolution->document !== null ? [
                    'uuid' => $card->resolution->document->uuid,
                    'title' => $card->resolution->document->title,
                ] : null,
            ] : null,
            'events' => $card->events->map(fn ($e) => [
                'type' => $e->type,
                'old_value' => $e->old_value,
                'new_value' => $e->new_value,
                'actor' => $e->actor?->full_name,
                'note' => $e->note,
                'created_at' => $e->created_at?->toIso8601String(),
            ])->all(),
        ]);
    }

    /** Assign and/or move a card (legal transitions only; audited). */
    public function update(string $uuid, Request $request): JsonResponse
    {
        $card = $this->find($uuid);
        $data = $request->validate([
            'status' => ['nullable', 'string'],
            'assigned_to' => ['nullable', 'integer'],
        ]);

        /** @var Admin $actor */
        $actor = $request->user();
        $assignedTo = $request->has('assigned_to') ? ($data['assigned_to'] ?? null) : false;

        try {
            $card = $this->service->update($card, $data['status'] ?? null, $assignedTo, $actor);
        } catch (RuntimeException $e) {
            return response()->json([
                'code' => $e->getMessage(),
                'message' => $e->getMessage() === 'illegal_transition'
                    ? 'Ese cambio de estado no está permitido desde el estado actual.'
                    : 'La persona asignada no es válida.',
            ], 422);
        }

        $card->load(['employee:id,uuid,full_name,convenio_id', 'employee.convenio:id,numero,name', 'assignedTo:id,full_name', 'topic:id,name', 'sourceMessage:id,content']);

        return response()->json(['card' => $this->cardSummary($card)]);
    }

    /** Send a human (hr_agent) reply into the employee's chat (audited). */
    public function reply(string $uuid, Request $request): JsonResponse
    {
        $card = $this->find($uuid);
        $data = $request->validate([
            'content' => ['required', 'string', 'max:4000'],
        ]);

        /** @var Admin $actor */
        $actor = $request->user();

        try {
            $message = $this->service->reply($card, trim($data['content']), $actor);
        } catch (RuntimeException $e) {
            return response()->json(['code' => $e->getMessage(), 'message' => 'La tarjeta no tiene conversación asociada.'], 422);
        }

        return response()->json([
            'message' => [
                'id' => $message->id,
                'role' => $message->role,
                'content' => $message->content,
                'author_label' => $actor->full_name,
                'created_at' => $message->created_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * Resolve a card, optionally converting the resolution into a published
     * `internal_hr_ruling` (the flywheel). The scope-confirm gate (Sprint-3
     * pattern, 409) and the no-override conflict gate (409) are enforced here.
     */
    public function resolve(string $uuid, Request $request): JsonResponse
    {
        $card = $this->find($uuid);
        $data = $request->validate([
            'resolution_text' => ['required', 'string', 'max:20000'],
            'convert' => ['nullable', 'boolean'],
            'topic_id' => ['nullable', 'integer'],
            'confirm_scope_change' => ['nullable', 'boolean'],
        ]);

        $convert = (bool) ($data['convert'] ?? false);

        // Scope-confirm gate (reuse the Sprint-3 409 pattern): publishing a ruling
        // inherits the asker's scope and changes which employees are answered, so
        // it cannot publish without explicit confirmation.
        if ($convert && ! ($data['confirm_scope_change'] ?? false)) {
            return response()->json([
                'code' => 'scope_confirmation_required',
                'message' => 'Publicar esta resolución como conocimiento hereda el ámbito del empleado '
                    .'(convenio, territorio, sector) y cambia a quién se responde. Confirma el cambio de ámbito para continuar.',
            ], 409);
        }

        /** @var Admin $actor */
        $actor = $request->user();

        try {
            $result = $this->service->resolve(
                $card,
                $actor,
                trim($data['resolution_text']),
                $convert,
                isset($data['topic_id']) ? (int) $data['topic_id'] : null,
            );
        } catch (RuntimeException $e) {
            return response()->json(['code' => $e->getMessage(), 'message' => 'No se pudo resolver la tarjeta.'], 422);
        }

        if (($result['outcome'] ?? null) === 'publish_blocked') {
            return response()->json([
                'code' => 'publish_blocked',
                'message' => 'No se puede publicar: existe un convenio oficial vigente para este ámbito y tema. '
                    .'Una resolución interna no puede prevalecer sobre el convenio — se ha devuelto la tarjeta a una persona.',
                'conflicts' => $result['conflicts'],
            ], 409);
        }

        /** @var EscalationCard $resolvedCard */
        $resolvedCard = $result['card'];
        $resolvedCard->load(['employee:id,uuid,full_name,convenio_id', 'employee.convenio:id,numero,name', 'assignedTo:id,full_name', 'topic:id,name', 'sourceMessage:id,content']);

        return response()->json([
            'card' => $this->cardSummary($resolvedCard),
            'document' => $result['document'] !== null ? [
                'uuid' => $result['document']->uuid,
                'title' => $result['document']->title,
            ] : null,
            'publish' => $result['publish'],
        ]);
    }

    private function find(string $uuid): EscalationCard
    {
        return EscalationCard::where('uuid', $uuid)->firstOrFail();
    }

    /**
     * @return array<string,mixed>
     */
    private function cardSummary(EscalationCard $card): array
    {
        return [
            'uuid' => $card->uuid,
            'status' => $card->status,
            'reason' => $card->reason,
            'reason_label' => self::REASON_LABELS[$card->reason] ?? $card->reason,
            'question' => $card->sourceMessage?->content,
            'employee' => $card->employee !== null ? [
                'uuid' => $card->employee->uuid,
                'full_name' => $card->employee->full_name,
                'convenio' => $card->employee->convenio !== null ? [
                    'id' => $card->employee->convenio->id,
                    'numero' => $card->employee->convenio->numero,
                    'name' => $card->employee->convenio->name,
                ] : null,
            ] : null,
            'assigned_to' => $card->assignedTo !== null ? [
                'id' => $card->assignedTo->id,
                'full_name' => $card->assignedTo->full_name,
            ] : null,
            'topic' => $card->topic !== null ? ['id' => $card->topic->id, 'name' => $card->topic->name] : null,
            'created_at' => $card->created_at?->toIso8601String(),
            'resolved_at' => $card->resolved_at?->toIso8601String(),
        ];
    }
}
