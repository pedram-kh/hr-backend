<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 7d (ADR-0024), migration 3 of 3 — the INERT AI succession proposal on
 * an expiry review task.
 *
 * 7a built the expiry queue (`document_review_tasks`, `type = 'expiry'`) and the
 * human-confirmed lineage write-side (`ReviewQueueController::resolveExpiry` →
 * `predecessor_document_id`), and deferred the AI *suggestion* of a successor to
 * 7d because it needs the semantic-comparison machinery this sprint builds.
 *
 * These three columns hold that suggestion and nothing else:
 *  - `ai_proposal`         jsonb — the relationship (`successor` | `conflict` |
 *                          `coexisting_sibling` | `uncertain`), the candidate
 *                          document, the score, the COMPARED PASSAGES (both
 *                          sides), the validity windows, and explicit
 *                          `uncertainty`. Everything the human needs to judge.
 *  - `ai_proposal_status`  `proposed` | `confirmed` | `rejected`.
 *  - `ai_proposed_at`      when the queued job wrote it.
 *
 * ⚠ THE PROPOSAL IS INERT (ADR-0020). Writing these columns changes NOTHING
 * about what is retrievable or what supersedes what. The AI never writes
 * `documents.predecessor_document_id`, never `documents.retrieval_status`, never
 * `documents.validity_*` — that is why the proposal lives on the TASK, not on
 * the document. A human confirm runs the UNCHANGED 7a write-side (same-convenio
 * enforced, predecessor never auto-retired, retirement still needing both
 * `retire_predecessor` and `confirm_scope_change`).
 *
 * `document_review_tasks.type` / `reason` are Laravel enums (→ Postgres CHECK) and
 * are DELIBERATELY NOT TOUCHED: nothing here adds a task type, and the §8.5
 * reverse-recheck flag reuses the existing `type = 'conflict'` value. That avoids
 * the introspect-drop-readd CHECK rewrite 7b-2 needed for `reference_facts.status`.
 *
 * ADDITIVE and NULLABLE (ADR-0007): every existing task reads
 * `ai_proposal = null` (= no proposal), no backfill, no reshaping.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_review_tasks', function (Blueprint $table) {
            $table->jsonb('ai_proposal')->nullable()->after('raw_unmatched_values');
            $table->string('ai_proposal_status', 16)->nullable()->after('ai_proposal');
            $table->timestamp('ai_proposed_at')->nullable()->after('ai_proposal_status');
        });
    }

    public function down(): void
    {
        Schema::table('document_review_tasks', function (Blueprint $table) {
            $table->dropColumn(['ai_proposal', 'ai_proposal_status', 'ai_proposed_at']);
        });
    }
};
