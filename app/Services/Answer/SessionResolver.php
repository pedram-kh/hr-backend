<?php

namespace App\Services\Answer;

use App\Models\ChatSession;
use App\Models\Employee;

/**
 * Sprint 13, build step 3 (plan.md §B.4.4 needs the SAME session-continuation
 * rule for the agent's conversation window as classic uses). Extracted
 * VERBATIM from `ChatService::resolveSession()` (unchanged since Sprint 5) —
 * both engines must resolve "the current session" identically, since the
 * agent's window builder (§B.4.4) is explicitly scoped to "the current
 * session only", the same session classic would have continued or started.
 */
class SessionResolver
{
    /** Most-recent session within the window, else a new one (Sprint 5 adds management). */
    public function resolve(Employee $employee, ?string $sessionUuid): ChatSession
    {
        if ($sessionUuid) {
            $existing = ChatSession::where('uuid', $sessionUuid)->where('employee_id', $employee->id)->first();
            if ($existing) {
                return $existing;
            }
        }

        $windowHours = (int) config('hr.session_window_hours', 24);
        $recent = ChatSession::where('employee_id', $employee->id)
            ->where('last_activity_at', '>=', now()->subHours($windowHours))
            ->orderByDesc('last_activity_at')
            ->first();

        return $recent ?? ChatSession::create([
            'employee_id' => $employee->id,
            'started_at' => now(),
            'last_activity_at' => now(),
        ]);
    }
}
