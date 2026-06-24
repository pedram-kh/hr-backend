<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Models\ChatSession;
use App\Models\Employee;
use App\Services\ChatService;
use App\Services\ConversationPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The employee chat surface (Sprint 2b-1). One prose turn in → a scoped, cited
 * answer OR an honest escalation out. hr-backend resolves scope, decides, and
 * persists; hr-ai retrieves + synthesises (ADR-0007/0015).
 */
class ChatController extends Controller
{
    public function message(Request $request, ChatService $chat): JsonResponse
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

        $result = $chat->handleMessage(
            $account,
            trim($data['question']),
            $data['session_uuid'] ?? null,
            isset($data['selected_job_category_id']) ? (int) $data['selected_job_category_id'] : null,
        );

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
}
