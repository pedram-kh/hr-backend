<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 7f (ADR-0028), migration 2 of 4 — which job categories belong to which
 * group node.
 *
 * A JOIN TABLE rather than a `convenio_group_id` column on
 * `convenio_job_categories`, so that Phase 2's proposals live entirely OUTSIDE
 * the salary-owned table and a `salary:import` re-run can never touch an
 * approved membership. `salary:import` and `salary.py` are untouched by this
 * sprint, and this is the structural reason that stays true.
 *
 * WHAT THIS IS FOR, AND WHAT IT IS NOT FOR. Membership only ever produces a
 * SUGGESTED DEFAULT in the employee directory form ("sugerido a partir de la
 * categoría — confirma"). The matcher never reads this table: Phase 3 compares
 * the employee's own `convenio_group_id` against a fact's bound nodes. So a
 * category with no membership is a perfectly fine steady state, and per the
 * plan's §3.4 census that is most of them — 6 of 94 memberships are
 * deterministic. Convenio 21 has no categories at all and needs none.
 *
 * The proposer may only ever attach an EXISTING category (ADR-0011: the category
 * vocabulary is closed and never minted). Group nodes are the new vocabulary;
 * categories are not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('convenio_group_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('convenio_group_id')->constrained('convenio_groups')->cascadeOnDelete();
            $table->foreignId('job_category_id')->constrained('convenio_job_categories')->cascadeOnDelete();

            $table->string('status', 32)->default('needs_review');
            $table->string('source', 32);
            $table->foreignId('approved_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            // One proposer run may only offer a category to one node, so this also
            // catches a malformed proposal before it is written.
            $table->unique(['convenio_group_id', 'job_category_id']);
            $table->index('job_category_id');
        });

        // ── "A category belongs to at most one node" — scoped to APPROVED rows ──
        // The plan wrote this as a flat UNIQUE (job_category_id). That would be
        // wrong here for a reason only visible once proposals are rows in the same
        // table: a second proposer run offering the same category (to the same node
        // or a different one) would be UN-INSERTABLE, so re-proposing a convenio
        // would fail instead of producing a reviewable alternative. Restricting the
        // constraint to `approved` keeps the invariant that actually matters — a
        // category resolves to ONE group, so the directory's suggested default is
        // never ambiguous — while leaving competing PROPOSALS free to coexist for a
        // human to choose between.
        DB::statement("CREATE UNIQUE INDEX convenio_group_categories_one_approved_per_category
            ON convenio_group_categories (job_category_id)
            WHERE status = 'approved'");
    }

    public function down(): void
    {
        Schema::dropIfExists('convenio_group_categories');
    }
};
