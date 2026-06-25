<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 5 (additive) — the conversation access log (ADR-0018). Append-only:
 * who viewed whose conversation, when, and how. EVERY access to a conversation
 * through the History browser writes a row — including a super_admin's read. No
 * role is exempt. This is the accountability safeguard that makes broad
 * oversight defensible to AEPD / comité de empresa; Sprint 9 hardens it
 * (retention/erasure + the audit/reporting layer over this table).
 *
 *  - `conversation_view` : one row per opened conversation (the full messages).
 *  - `history_search`    : one lighter row per search run (snippets are brief by
 *    design — a match fragment, never enough to read the conversation's
 *    substance, so a listing is not per-employee disclosure).
 *
 * Same append-only posture as `escalation_events` / `tag_events` (no updated_at,
 * never UPDATE/DELETE of history).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_access_log', function (Blueprint $table) {
            $table->id();
            // WHO viewed (the acting admin — logged even for super_admin).
            $table->foreignId('admin_id')->constrained('admins')->cascadeOnDelete();
            // WHOSE conversation (null only for a search-run event with no single subject).
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            // The session viewed (null for a history_search listing event).
            $table->foreignId('chat_session_id')->nullable()->constrained('chat_sessions')->nullOnDelete();
            // conversation_view | history_search
            $table->string('access_type');
            // Surface/route marker (e.g. 'history') + the search query for a search event.
            $table->string('context')->nullable();
            $table->timestamp('created_at')->nullable(); // append-only — no updated_at

            $table->index(['admin_id', 'id']);
            $table->index(['employee_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_access_log');
    }
};
