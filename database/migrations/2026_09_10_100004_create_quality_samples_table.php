<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 8, Step 6 (plan.md §6.1, ADR-0030): `quality_samples` — one row per
 * turn drawn by the monthly stratified sample. `verdict`/`failure_kind`/
 * `note`/`reviewed_by`/`reviewed_at` all start null and are filled in by the
 * ONE review decision an admin makes on this row (§6.3 — "one decision per
 * turn"). `stratum_path`/`stratum_territory_id` are FROZEN at draw time
 * (plan.md's own reasoning: a later re-classification of the underlying turn
 * must not retroactively change what was sampled for).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quality_samples', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('message_id')->constrained('chat_messages')->cascadeOnDelete();
            $table->string('sampled_for_month', 7); // 'YYYY-MM'
            $table->integer('seed');
            $table->string('stratum_path', 64)->nullable();
            $table->foreignId('stratum_territory_id')->nullable()->constrained('territories')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->string('verdict', 16)->nullable(); // correct | partially | wrong
            $table->string('failure_kind', 32)->nullable(); // wrong_scope | wrong_figure | stale_document | unclear | other
            $table->text('note')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('escalation_card_id')->nullable()->constrained('escalation_cards')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['sampled_for_month', 'verdict']);
            $table->index(['stratum_path', 'stratum_territory_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quality_samples');
    }
};
