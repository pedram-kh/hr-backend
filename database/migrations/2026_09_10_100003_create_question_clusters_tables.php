<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 8, Step 5 (plan.md §4.2, ADR-0030): `question_clusters` +
 * `question_cluster_members` — the nightly greedy-threshold question
 * clustering job's storage. NO LLM label — the label is the MEDOID (the
 * member with the highest mean similarity to every other member), computed
 * directly from the same similarity matrix the clustering pass already
 * builds. τ (cosine threshold) is logged per cluster's min/max pairwise
 * similarity so it can be recalibrated later against real traffic (plan.md
 * §12, resolved open question #3 — unmeasured by design until then).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('question_clusters', function (Blueprint $table) {
            $table->id();
            $table->date('run_date'); // the nightly run this cluster belongs to (re-clustered each run — see review.md for the incremental-vs-rebuild note)
            $table->text('medoid_text');
            $table->unsignedInteger('distinct_text_count');
            $table->unsignedInteger('member_count'); // total chat_messages occurrences, not just distinct strings
            $table->float('min_similarity')->nullable(); // null when member_count = 1
            $table->float('max_similarity')->nullable();
            $table->float('threshold_used'); // τ at run time — logged so a later recalibration is auditable
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->string('top_escalation_reason', 64)->nullable();
            $table->float('escalation_rate')->nullable();
            $table->unsignedInteger('headcount_weight')->default(0); // sum of askers' convenio headcount (plan.md §4.2 step 7)
            $table->timestamps();

            $table->index(['run_date']);
        });

        Schema::create('question_cluster_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('question_cluster_id')->constrained('question_clusters')->cascadeOnDelete();
            $table->foreignId('chat_message_id')->constrained('chat_messages')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['question_cluster_id', 'chat_message_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('question_cluster_members');
        Schema::dropIfExists('question_clusters');
    }
};
