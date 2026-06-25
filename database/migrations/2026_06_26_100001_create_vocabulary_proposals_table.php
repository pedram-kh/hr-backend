<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 7a — managed vocabulary growth (ADR-0011, ADR-0020).
 *
 * Generalizes the `topics` propose/approve pattern to ALL scoping vocabulary
 * (territory / sector / convenio): an agent or a human PROPOSES that a value
 * should exist or that an unmatched string is a VARIANT of an existing value;
 * a human APPROVES it into the controlled vocabulary (folding into `aliases` —
 * the strong default — or creating a genuinely-new value — the deliberate
 * action). The AI can ONLY propose (`proposed_by_source = ai_agent`); approval
 * is a human action gated by the `vocabulary.approve` ability (super_admin),
 * who may propose-and-approve in one step.
 *
 * Additive only. No existing table changes shape. The vocabulary WRITE itself
 * (an alias append or a new row) happens at approval time in the registry-owned
 * tables, with provenance.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vocabulary_proposals', function (Blueprint $table) {
            $table->id();

            // Which closed vocabulary this proposal targets.
            $table->enum('facet', ['territory', 'sector', 'convenio']);

            // The literal value the agent/human proposes (the raw unmatched string).
            $table->string('proposed_value');

            // When offered as a VARIANT (the default, ADR-0011): the existing value
            // to fold the proposed string into as an alias. Null for a new-value
            // proposal. `variant_of_type` records the target table for clarity.
            $table->string('variant_of_type')->nullable();
            $table->unsignedBigInteger('variant_of_id')->nullable();
            $table->decimal('variant_similarity', 4, 3)->nullable();

            // Chosen at approval: fold into aliases (default) vs create a new value.
            $table->enum('resolution', ['alias', 'new_value'])->nullable();

            // Provenance + linkage so an approval can resolve the originating doc.
            $table->foreignId('source_document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->foreignId('review_task_id')->nullable()->constrained('document_review_tasks')->nullOnDelete();

            $table->enum('status', ['proposed', 'approved', 'rejected'])->default('proposed');

            // Who/what proposed (the AI lane parallels tag_events.source).
            $table->enum('proposed_by_source', ['ai_agent', 'admin_manual']);
            $table->foreignId('proposed_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();

            // The human approver (always a human — never the AI). For a super_admin
            // propose-and-approve, this equals proposed_by_admin_id.
            $table->foreignId('approved_by')->nullable()->constrained('admins')->nullOnDelete();

            // The vocabulary row the approval resolved to (the new/folded value), so
            // the trail points at what was written. Null until approved.
            $table->string('resolved_vocab_type')->nullable();
            $table->unsignedBigInteger('resolved_vocab_id')->nullable();

            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['status', 'facet']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vocabulary_proposals');
    }
};
