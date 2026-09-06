<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 7d (ADR-0024), migration 1 of 3 — the semantic fence's AUDIT payload.
 *
 * `escalation_events` is the append-only activity log of the Sprint-4 board. Its
 * `type` column is a free `string` (not an enum), so the two new event types the
 * semantic fence records — `publish_blocked` with `reason = semantic_overlap`,
 * and the new `publish_acknowledged_overlap` — need NO migration.
 *
 * What DOES need one is the evidence. Acceptance criterion (A2) requires the
 * block/acknowledge event to record the matched chunk ids + scores so a human
 * can later answer "*why* did this block?". The table has only `old_value`,
 * `new_value` and `note` (all text), so that evidence would have to be stuffed
 * into prose and could never be queried or counted. One additive nullable
 * `jsonb` column makes it machine-readable:
 *
 *   { "reason": "semantic_overlap",
 *     "max_score": 0.871, "threshold": 0.75, "review_band": 0.60,
 *     "probe_count": 4, "eligible_total": 132,
 *     "matches": [ { "chunk_id": 41207, "document_id": 88, "document_uuid": "…",
 *                    "chunk_index": 12, "page_from": 9, "score": 0.871,
 *                    "probe_index": 2, "excerpt": "…" } ] }
 *
 * ADDITIVE and NULLABLE (ADR-0007): every existing event row keeps `detail =
 * null`, nothing is backfilled, no existing column changes shape, and the
 * append-only posture is preserved (rows are INSERTed, never UPDATEd).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('escalation_events', function (Blueprint $table) {
            $table->jsonb('detail')->nullable()->after('note');
        });
    }

    public function down(): void
    {
        Schema::table('escalation_events', function (Blueprint $table) {
            $table->dropColumn('detail');
        });
    }
};
