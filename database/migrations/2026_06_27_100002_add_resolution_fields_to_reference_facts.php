<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 7d (ADR-0024), migration 2 of 3 — fact version RESOLUTION provenance.
 *
 * 7b-2 gave a flagged duplicate pair `duplicate_of_id` + `uncertainty.field =
 * 'version'` — a SIGNAL only ("resolution is 7d"). These columns record the
 * human's decision on that pair, and the version lineage a supersede creates.
 *
 *  - `resolution`       the human's verdict: `supersedes` | `superseded` |
 *                       `coexists` | `rejected_duplicate`. A resolved pair leaves
 *                       the unresolved-duplicate queue; `duplicate_of_id` is
 *                       RETAINED as history (the flag is resolved, never erased).
 *  - `superseded_by_id` version lineage: the older fact names the newer one that
 *                       closed its validity window. The fact-level sibling of
 *                       `documents.predecessor_document_id`.
 *  - `resolved_by` /
 *    `resolved_at`      who decided, and when (the append-only `tag_events` rows
 *                       carry the full narrative; these make it queryable).
 *
 * ⚠ SUPERSEDE CLOSES VALIDITY — IT NEVER DELETES. A supersede sets the OLDER
 * fact's `validity_end = newer.validity_start − 1 day` and leaves it `verified`
 * for its window. Both rows remain. That is the whole point of version history:
 * a question dated inside the old window must still get the OLD value. There is
 * deliberately no soft-delete and no merge here.
 *
 * `resolution` is a plain nullable VARCHAR, NOT a Laravel `enum`. That is
 * deliberate: an enum becomes a Postgres CHECK, and adding a value later then
 * requires the introspect-drop-readd dance 7b-2 had to perform for
 * `reference_facts.status`. The allowed set is validated in the FormRequest
 * (application-level, cheap to extend) — see ResolveFactDuplicateRequest.
 *
 * ADDITIVE and NULLABLE (ADR-0007): no backfill, no existing column reshaped;
 * every fact that exists today reads `resolution = null` (= unresolved).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reference_facts', function (Blueprint $table) {
            $table->string('resolution', 32)->nullable()->after('duplicate_of_id');
            $table->foreignId('superseded_by_id')->nullable()->after('resolution')
                ->constrained('reference_facts')->nullOnDelete();
            $table->foreignId('resolved_by')->nullable()->after('superseded_by_id')
                ->constrained('admins')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable()->after('resolved_by');

            // The unresolved-duplicate queue reads (duplicate_of_id IS NOT NULL
            // AND resolution IS NULL); the resolution index keeps that cheap.
            $table->index('resolution');
        });
    }

    public function down(): void
    {
        Schema::table('reference_facts', function (Blueprint $table) {
            $table->dropIndex(['resolution']);
            $table->dropConstrainedForeignId('resolved_by');
            $table->dropConstrainedForeignId('superseded_by_id');
            $table->dropColumn('resolution');
        });
    }
};
