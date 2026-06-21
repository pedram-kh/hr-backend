<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 1 — two-reason review routing + raw unmatched value (ADR-0011).
 *
 *  - `reason`: `unresolved` (parser had nothing to resolve — LLM-eligible in a
 *    later sprint) vs `conflict` (resolved-but-contradicts-registry —
 *    human-adjudicated; the LLM may suggest but never auto-apply).
 *  - `raw_unmatched_values`: the literal string(s) the parser could not resolve,
 *    so a human (or the future agent) can later decide "fold into an existing
 *    value's aliases" vs "propose a new vocabulary value."
 *
 * Both fields are the data foundation only; the LLM rescue path / propose-new
 * UI is deferred to the LLM-tagging-tier sprint.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_review_tasks', function (Blueprint $table) {
            $table->enum('reason', ['unresolved', 'conflict'])->nullable()->after('type');
            $table->jsonb('raw_unmatched_values')->nullable()->after('reason');
        });
    }

    public function down(): void
    {
        Schema::table('document_review_tasks', function (Blueprint $table) {
            $table->dropColumn(['reason', 'raw_unmatched_values']);
        });
    }
};
