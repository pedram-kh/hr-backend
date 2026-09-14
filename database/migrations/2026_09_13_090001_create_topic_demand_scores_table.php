<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 10c, D7 — the nightly per-TOPIC demand score, written by the
 * existing `questions:cluster` command alongside `question_clusters`. Reuses
 * the SAME "unanswered" formula `unansweredRanking()` already computes per
 * cluster (escalation_rate x volume x headcount_weight) — aggregated by
 * `TopicLexicon` topic_key instead of by cluster, so the reference-facts
 * review queue can rank AI-proposed facts by real employee demand for that
 * topic (presentation-only — ordered AFTER uncertainty/confidence; safety
 * outranks demand, per the build-authorization's D7).
 *
 * `topic_id` is NULLABLE: a topic_key with no approved `topics` row yet
 * (preaviso, descanso, horas_extra as of this sprint — review.md finding 2)
 * still gets a row here, because the demand SIGNAL is real even before the
 * topic exists as approved vocabulary (ADR-0011) — it is simply not
 * joinable to `reference_facts.topic_id` until that topic is created.
 *
 * Full-rebuild-per-run_date (delete-then-insert), the same idempotency idiom
 * `question_clusters` already uses.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('topic_demand_scores', function (Blueprint $table) {
            $table->id();
            $table->date('run_date');
            $table->string('topic_key', 64); // TopicLexicon key — always present, even with no approved topic yet
            $table->foreignId('topic_id')->nullable()->constrained('topics')->nullOnDelete();
            $table->unsignedInteger('volume')->default(0); // distinct-message occurrences matching this topic's anchors
            $table->float('escalation_rate')->nullable();
            $table->unsignedInteger('headcount_weight')->default(0);
            $table->float('score')->default(0);
            $table->timestamps();

            $table->unique(['run_date', 'topic_key']);
            $table->index(['topic_id', 'run_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('topic_demand_scores');
    }
};
