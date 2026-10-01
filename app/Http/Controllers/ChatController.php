<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateEscalationExplanationText;
use App\Models\Admin;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\Employee;
use App\Models\EscalationCard;
use App\Models\MessageFeedback;
use App\Models\MessageTrace;
use App\Services\AnswerEngineDispatcher;
use App\Services\ConversationPresenter;
use App\Support\EscalationExplainer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The employee chat surface (Sprint 2b-1). One prose turn in → a scoped, cited
 * answer OR an honest escalation out. hr-backend resolves scope, decides, and
 * persists; hr-ai retrieves + synthesises (ADR-0007/0015).
 */
class ChatController extends Controller
{
    public function message(Request $request, AnswerEngineDispatcher $dispatcher): JsonResponse
    {
        $account = $request->user();
        // Chat is an EMPLOYEE surface. Admins use the admin console.
        if ($account instanceof Admin || ! $account instanceof Employee) {
            return response()->json(['message' => 'Chat is for employees.'], 403);
        }

        $data = $request->validate([
            'question' => ['required', 'string', 'max:2000'],
            'session_uuid' => ['nullable', 'string'],
            // The unverified category the employee picked from the constrained
            // salary disambiguation (§4). FK-validated to their convenio inside
            // SalaryAnswerService — a free-text / out-of-convenio value resolves
            // to null and the turn escalates rather than guessing.
            'selected_job_category_id' => ['nullable', 'integer'],
        ]);

        // Sprint 13 (plan.md §E.15 step 2): the dispatcher resolves classic vs.
        // agent (env `HR_ANSWER_ENGINE` baseline, DB override, §F.14) — this is
        // the ONE seam. Nothing else about this endpoint changes.
        $result = $dispatcher->handle(
            $account,
            trim($data['question']),
            $data['session_uuid'] ?? null,
            isset($data['selected_job_category_id']) ? (int) $data['selected_job_category_id'] : null,
        );

        // Sprint 10a — Correction-01 (E3, ADR-0018 spirit: the server is the
        // boundary, not CSS). This is the EMPLOYEE'S OWN live turn — `trace`
        // ("Cómo llegué a esto": router confidence, model name, chunk counts)
        // and citation excerpts (the FUENTES snippet block) are admin material
        // and never leave hr-backend on this endpoint. `handleMessage()`'s
        // return value, `$result['trace']`/`$result['citations']`, and
        // everything persisted to `message_traces`/`message_citations` are
        // UNCHANGED above this line — this reshapes only the array about to be
        // serialised as this response, using the exact same helper the
        // employee's session-hydration endpoint uses (ConversationPresenter),
        // so "one source line, one place it's computed" holds for both.
        $sourceLabels = ConversationPresenter::sourceLabels($result['citations'] ?? []);
        $generalLane = ConversationPresenter::generalLanePayload($result['trace'] ?? []);
        unset($result['trace']);
        $result['citations'] = [];
        $result['source_labels'] = $sourceLabels;
        $result['general_lane'] = $generalLane;

        return response()->json($result);
    }

    /**
     * Load the caller's OWN most-recent chat session, ordered messages included
     * (Sprint 4, Q-D). This is how the employee sees a human (hr_agent) reply
     * land in the chat — the screen hydrates on mount and polls. SELF-SCOPED:
     * the session is resolved from the authenticated employee only (never a
     * caller-supplied id), and a human reply is attributed as "Recursos Humanos"
     * with no admin PII. There is NO session list/picker (that is Sprint 5).
     */
    public function session(Request $request, ConversationPresenter $presenter): JsonResponse
    {
        $account = $request->user();
        if ($account instanceof Admin || ! $account instanceof Employee) {
            return response()->json(['message' => 'Chat is for employees.'], 403);
        }

        $session = ChatSession::where('employee_id', $account->id)
            ->orderByDesc('last_activity_at')
            ->first();

        if ($session === null) {
            return response()->json(['session_uuid' => null, 'messages' => []]);
        }

        return response()->json([
            'session_uuid' => $session->uuid,
            'messages' => $presenter->present($session, ConversationPresenter::AUDIENCE_EMPLOYEE),
        ]);
    }

    /**
     * Sprint 8, Step 8 (plan.md §7) — thumbs up/down (+ optional comment) on
     * an assistant turn. Additive and orthogonal: nothing else reads this
     * back into any decision (the answer loop is untouched by construction).
     * Self-scoped exactly like `session()` above: the message must belong to
     * THIS employee's own session, or the write is rejected — never a
     * caller-supplied employee_id, never another employee's turn. Upsert on
     * `message_id` (unique) — a second click replaces, never duplicates.
     */
    public function feedback(Request $request, int $messageId): JsonResponse
    {
        $account = $request->user();
        if ($account instanceof Admin || ! $account instanceof Employee) {
            return response()->json(['message' => 'Chat is for employees.'], 403);
        }

        $data = $request->validate([
            'rating' => ['required', 'string', 'in:up,down'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        $message = ChatMessage::where('id', $messageId)->where('role', 'assistant')->first();
        if ($message === null || $message->session?->employee_id !== $account->id) {
            return response()->json(['message' => 'Message not found.'], 404);
        }

        $feedback = MessageFeedback::updateOrCreate(
            ['message_id' => $message->id],
            ['employee_id' => $account->id, 'rating' => $data['rating'], 'comment' => $data['comment'] ?? null],
        );

        return response()->json(['feedback' => $feedback->only(['message_id', 'rating', 'comment'])]);
    }

    /**
     * Sprint 13, build step 8 (plan.md §D.13/§E.15) — "¿Quieres que lo revise
     * RR. HH.?" on an answered turn. Self-scoped exactly like `feedback()`
     * above: the message must be this employee's own assistant turn. Answer-
     * only: a turn that is already escalated has already reached HR, and an
     * `ask`/`needs_category` turn is not a finished answer to review — both
     * 422. Idempotent: a second tap on the SAME message returns the existing
     * card's uuid rather than creating a second one (the unique partial index
     * on `reviewed_message_id`, migration 2026_09_28_234500_..., is the
     * actual guarantee under a race; `firstOrCreate` is the common case).
     */
    public function requestReview(Request $request, int $messageId): JsonResponse
    {
        $account = $request->user();
        if ($account instanceof Admin || ! $account instanceof Employee) {
            return response()->json(['message' => 'Chat is for employees.'], 403);
        }

        $message = ChatMessage::where('id', $messageId)->where('role', 'assistant')->first();
        if ($message === null || $message->session?->employee_id !== $account->id) {
            return response()->json(['message' => 'Message not found.'], 404);
        }

        $trace = MessageTrace::where('message_id', $message->id)->first();
        $outcome = $trace?->trace['floor_decision']['outcome'] ?? null;
        if ($outcome !== 'answer') {
            return response()->json([
                'message' => 'Only an answered message can be sent for review.',
            ], 422);
        }

        $card = EscalationCard::where('reviewed_message_id', $message->id)->first();
        if ($card === null) {
            $explanation = EscalationExplainer::explain('employee_requested_review', $trace?->trace ?? []);
            $card = EscalationCard::create([
                'chat_session_id' => $message->session_id,
                // The paired user question, if one is findable directly before
                // this answer in the same session — never a caller-supplied
                // id. Falls back to null (the board still shows the card;
                // `reviewedMessage` below carries the answer either way).
                'source_message_id' => ChatMessage::where('session_id', $message->session_id)
                    ->where('role', 'user')
                    ->where('id', '<', $message->id)
                    ->orderByDesc('id')
                    ->first()?->id,
                'employee_id' => $account->id,
                'reason' => 'employee_requested_review',
                'status' => 'new',
                'reviewed_message_id' => $message->id,
                'explanation_facts' => $explanation,
                'fix_action' => $explanation['fix_action'],
                'fix_surface' => $explanation['fix_surface'],
                'fix_link' => $explanation['fix_link'],
            ]);

            GenerateEscalationExplanationText::dispatch($card->uuid)->afterCommit();
        }

        return response()->json(['escalation_uuid' => $card->uuid]);
    }
}
