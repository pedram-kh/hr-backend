<?php

namespace App\Services;

use App\Models\Admin;
use App\Models\ChatSession;
use App\Models\ConversationAccessLog;

/**
 * Writes the append-only conversation access log (ADR-0018). EVERY History
 * access goes through here — including a super_admin's. No role is exempt; this
 * is the accountability safeguard that makes broad oversight defensible.
 */
class ConversationAccessLogger
{
    /** One row per OPENED conversation (the full messages of a session). */
    public function logView(Admin $admin, ChatSession $session): void
    {
        ConversationAccessLog::create([
            'admin_id' => $admin->id,
            'employee_id' => $session->employee_id,
            'chat_session_id' => $session->id,
            'access_type' => ConversationAccessLog::TYPE_VIEW,
            'context' => 'history',
            'created_at' => now(),
        ]);
    }

    /**
     * One lighter row per SEARCH run (no single subject — snippets are brief by
     * design, so a listing is not per-employee disclosure; opening a result
     * writes a conversation_view).
     */
    public function logSearch(Admin $admin, string $query): void
    {
        ConversationAccessLog::create([
            'admin_id' => $admin->id,
            'employee_id' => null,
            'chat_session_id' => null,
            'access_type' => ConversationAccessLog::TYPE_SEARCH,
            'context' => 'history:q='.mb_substr($query, 0, 120),
            'created_at' => now(),
        ]);
    }
}
