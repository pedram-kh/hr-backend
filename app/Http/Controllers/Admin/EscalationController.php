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
        // Sprint 7g Item 1 correction: the live `reason` column value is
        // `sensitive_topic` (see the CHECK-constraint enum chain in
        // database/migrations), never bare `sensitive` — that key never
        // matched and always fell through to the `$card->reason` fallback
        // below. Kept both keys (harmless) so a future literal `sensitive`
        // does not silently regress.
        'sensitive' => 'Tema sensible',
        'sensitive_topic' => 'Tema sensible',
        'legal_medical' => 'Legal / médico',
        'other_employee' => 'Sobre otra persona',
        'explicit_request' => 'Petición explícita',
        'conflict' => 'Conflicto dato/convenio',
        'salary_coverage_gap' => 'Hueco en tablas salariales',
        'salary_not_in_chat' => 'Salario no disponible',
        // Sprint 7g Item 1 correction: added — was missing entirely, so a
        // reference-fact coverage-gap card displayed its raw reason string.
        'reference_fact_coverage_gap' => 'Hueco en datos de referencia',
        // Sprint 8, Step 6: added — was missing entirely, so a monthly
        // quality-sample "wrong" card displayed its raw reason string.
        'quality_sample_wrong' => 'Muestra de calidad incorrecta',
        // Sprint 10a (ADR-0032). Deliberately named as a corpus gap, not as a
        // fallback failure: the fix is always to make the convenio text
        // retrievable, never to let the Estatuto answer in its place.
        //
        // Correction-02 (CP-4 step 6, C2-1): originally 'Hueco en el texto
        // del convenio' — too close in meaning to any generic prose-retrieval
        // gap for a reviewer to tell apart on the badge/filter alone, even
        // though the underlying cause (a convenio text that exists but is
        // deliberately NOT served — expired/no successor, mid-ingest, tagging
        // under review, or an unreadable scan) is legally distinct from an
        // ordinary retrieval miss: this is the one case where the system
        // refuses to fall back to the Estatuto. Renamed to name that
        // specifically, so it reads as its own category everywhere it renders.
        'estatuto_fallback_gap' => 'Convenio vencido / sin texto vigente',
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
    public function show(string $uuid, Request $request): JsonResponse
    {
        $card = $this->find($uuid);
        $card->load([
            'employee:id,uuid,full_name,email,convenio_id,territory_id,job_category_id,convenio_group_id,start_date',
            'employee.convenio:id,numero,name',
            // Correction-02 (CP-4 step 6, C2-2): the extra relations the new
            // card-detail-only employee block needs (territory / category /
            // group / seniority). Detail-only by construction: `index()`'s own
            // eager-load (the board LIST query, above) is untouched, and
            // `cardSummary()` — shared by both endpoints — never reads these,
            // so the list payload does not grow even though this is the same
            // `employee` relation.
            'employee.territory:id,code,name',
            'employee.jobCategory:id,name',
            'employee.convenioGroup:id,parent_id,label,code_normalized',
            'employee.convenioGroup.parent:id,label',
            'assignedTo:id,full_name', 'topic:id,name', 'sourceMessage:id,content', 'resolution', 'events.actor:id,full_name',
        ]);

        // Sprint-5 tightening (ADR-0018 §4.4): the conversation PAYLOAD requires
        // `escalation.work` OR `history.view_all`. This denies a knowledge_editor
        // (neither) any chat access — including here — WITHOUT loosening the
        // hr_agent boundary (hr_agent has escalation.work and still sees only this
        // card's session, keyed to card.chat_session_id, unchanged). The card
        // meta/board listing stays broadly readable; only the messages are gated.
        $actor = $request->user();
        $canSeeConversation = $actor !== null
            && method_exists($actor, 'can')
            && ($actor->can('escalation.work') || $actor->can('history.view_all'));

        // Correction-02 (C2-2): the employee-context block (name/email/
        // territory/category-group/seniority) is narrower than the
        // conversation gate above — `escalation.work` only, per spec ("visible
        // to escalation.work-scoped viewers"), so a history.view_all-only
        // auditor sees the conversation but NOT this block. Same ability that
        // already gates every write on this card (assign/move/reply/resolve)
        // — no new permission introduced. No new access-log write either: this
        // read path (`show()`) had no dedicated access-log call to begin with
        // (unlike `HistoryController`'s `ConversationAccessLogger`, which is a
        // different, broader browsing surface), so there was nothing to reuse
        // or duplicate — confirmed by inspection, not assumed.
        $canSeeEmployeeContext = $actor !== null
            && method_exists($actor, 'can')
            && $actor->can('escalation.work');

        $conversation = ($canSeeConversation && $card->session !== null)
            ? $this->presenter->present($card->session, ConversationPresenter::AUDIENCE_ADMIN)
            : [];

        return response()->json([
            'card' => $this->cardSummary($card),
            'conversation' => $conversation,
            'conversation_restricted' => ! $canSeeConversation,
            'employee_context' => $canSeeEmployeeContext ? $this->employeeContext($card) : null,
            'employee_context_restricted' => ! $canSeeEmployeeContext,
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
     *
     * Sprint 7d (ADR-0024) adds one more 409 — `publish_requires_acknowledgement`
     * — for the semantic fence's review band. Three publish-time 409s now exist and
     * they are NOT interchangeable: `scope_confirmation_required` (who gets
     * answered), `publish_blocked` (refused), `publish_requires_acknowledgement`
     * (asked). Only the third can be satisfied by re-POSTing a flag.
     */
    public function resolve(string $uuid, Request $request): JsonResponse
    {
        $card = $this->find($uuid);
        $data = $request->validate([
            'resolution_text' => ['required', 'string', 'max:20000'],
            'convert' => ['nullable', 'boolean'],
            'topic_id' => ['nullable', 'integer'],
            'confirm_scope_change' => ['nullable', 'boolean'],
            // Sprint 7d (ADR-0024): the human's explicit "I read the near-passages,
            // there is no overlap". Per-attempt, never stored — the same shape as
            // `confirm_scope_change`. It satisfies ONLY the review band; it can
            // never unblock a `semantic_overlap` or a structural conflict.
            'acknowledge_semantic_overlap' => ['nullable', 'boolean'],
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
                (bool) ($data['acknowledge_semantic_overlap'] ?? false),
            );
        } catch (RuntimeException $e) {
            return response()->json(['code' => $e->getMessage(), 'message' => 'No se pudo resolver la tarjeta.'], 422);
        }

        // Convert-by-reason policy (Sprint 6, ADR-0019, restrict-only). The card's
        // escalation reason is not in the effective convert-by-reason allow-set
        // (e.g. a sensitive_topic card, which can never be converted). Nothing is
        // published; the card is untouched.
        if (($result['outcome'] ?? null) === 'convert_blocked') {
            return response()->json([
                'code' => 'convert_blocked',
                'message' => 'Esta escalación no puede convertirse en conocimiento por su motivo ('
                    .($result['reason'] ?? '—').'). La política de guardarraíles restringe qué motivos '
                    .'son convertibles; un tema sensible nunca puede publicarse como respuesta.',
                'reason' => $result['reason'] ?? null,
                'allowed_reasons' => $result['allowed'] ?? [],
            ], 409);
        }

        if (($result['outcome'] ?? null) === 'publish_blocked') {
            // Sprint 7d: the same 409 code and the same `conflicts` shape as before
            // (the existing UI keeps working unchanged); `reason` distinguishes the
            // structural block from the new semantic one, and `passages` carries the
            // overlapping convenio text so the human can judge for themselves.
            $semantic = ($result['reason'] ?? null) === 'semantic_overlap';

            return response()->json([
                'code' => 'publish_blocked',
                'message' => $semantic
                    ? 'No se puede publicar: el texto del convenio oficial vigente para este ámbito ya parece '
                        .'regular este punto concreto. Una resolución interna no puede prevalecer sobre el convenio — '
                        .'revisa los pasajes coincidentes; se ha devuelto la tarjeta a una persona.'
                    : 'No se puede publicar: existe un convenio oficial vigente para este ámbito y tema. '
                        .'Una resolución interna no puede prevalecer sobre el convenio — se ha devuelto la tarjeta a una persona.',
                'reason' => $result['reason'] ?? null,
                'conflicts' => $result['conflicts'],
                'passages' => $result['passages'] ?? [],
                'max_score' => $result['max_score'] ?? null,
            ], 409);
        }

        // Sprint 7d band 2 (ADR-0024): a PLAUSIBLE overlap, or a comparison that
        // could not be made. Not a rejection — a question. Nothing was published,
        // the draft is untouched and the card is NOT re-opened; re-POST with
        // `acknowledge_semantic_overlap = true` to proceed. Never a silent pass.
        if (($result['outcome'] ?? null) === 'publish_requires_acknowledgement') {
            $unreadable = in_array($result['reason'] ?? null, ['semantic_compare_unavailable', 'semantic_no_text_to_compare'], true);

            return response()->json([
                'code' => 'publish_requires_acknowledgement',
                'message' => $unreadable
                    // The copy names the cause explicitly, so this prompt is never
                    // mistaken for a real near-passage — an acknowledgement the human
                    // learns to click through is a fence that has quietly opened.
                    ? 'No se ha podido comparar esta resolución con el texto del convenio vigente '
                        .'(la comparación semántica no está disponible o el convenio del ámbito no tiene texto legible). '
                        .'Eso no confirma que no haya solapamiento: revísalo y confirma explícitamente para publicar.'
                    : 'Esta resolución se parece a pasajes del convenio oficial vigente en este ámbito, '
                        .'pero no lo bastante como para bloquear la publicación. Revisa los pasajes y confirma '
                        .'explícitamente que no hay solapamiento para publicar.',
                'reason' => $result['reason'] ?? null,
                'passages' => $result['passages'] ?? [],
                'max_score' => $result['max_score'] ?? null,
                'comparison_unavailable' => $unreadable,
                'detail' => $result['failure_detail'] ?? null,
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
     * Correction-02 (CP-4 step 6, C2-2) — the card-detail-ONLY employee block:
     * name, email, territory, category/group, seniority (where recorded). Never
     * called from `cardSummary()`, so it never reaches the board's list view.
     *
     * @return array<string,mixed>|null
     */
    private function employeeContext(EscalationCard $card): ?array
    {
        $employee = $card->employee;
        if ($employee === null) {
            return null;
        }

        return [
            'full_name' => $employee->full_name,
            'email' => $employee->email,
            'territory' => $employee->territory !== null ? [
                'id' => $employee->territory->id,
                'name' => $employee->territory->name,
            ] : null,
            'job_category' => $employee->jobCategory !== null ? [
                'id' => $employee->jobCategory->id,
                'name' => $employee->jobCategory->name,
            ] : null,
            'convenio_group' => $employee->convenioGroup !== null ? [
                'id' => $employee->convenioGroup->id,
                'path_label' => $employee->convenioGroup->pathLabel(),
            ] : null,
            // "seniority where recorded" — there is no dedicated seniority
            // field; `start_date` (hire date) is nullable and often absent on
            // older/imported profiles, so this is null exactly when it is not
            // recorded, never a guessed/derived date.
            'seniority' => $employee->start_date !== null ? [
                'start_date' => $employee->start_date->toDateString(),
                'years' => (int) $employee->start_date->diffInYears(now()),
            ] : null,
        ];
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
            // Sprint 7g Item 1 (ADR-0029). `explanation_text` is the AI
            // paragraph when it passed the no-new-claims check, else null —
            // the frontend falls back to rendering `explanation_facts` as
            // sentences itself (same deterministic renderer, ported client-
            // side: `factsToSentences` in `lib/escalationExplanation.ts`).
            'explanation_facts' => $card->explanation_facts,
            'explanation_text' => $card->explanation_text,
            'fix_action' => $card->fix_action,
            'fix_surface' => $card->fix_surface,
            'fix_link' => $card->fix_link,
        ];
    }
}
