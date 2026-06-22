<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Models\Employee;
use App\Services\ChatService;
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
        ]);

        $result = $chat->handleMessage($account, trim($data['question']), $data['session_uuid'] ?? null);

        return response()->json($result);
    }
}
