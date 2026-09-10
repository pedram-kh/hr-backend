<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 8, Step 8 (plan.md §7, ADR-0030) — `message_feedback`. The one
 * genuinely optional item this sprint ("keep it, but ship last and treat its
 * removal as a zero-cost decision" — plan.md §7); additive and orthogonal (a
 * click stores a row; nothing reads it back into any decision the answer
 * loop makes — the hard "answer-loop-frozen" constraint is untouched by
 * construction, not by discipline).
 *
 * One row per ASSISTANT message per employee: `message_id` is unique, so a
 * second click on the same message REPLACES the row (upsert via
 * `updateOrCreate`), never duplicates. `employee_id` is denormalized for
 * query convenience — the same pattern `escalation_cards.employee_id`
 * already uses (a join through `chat_sessions` would resolve it, but every
 * satisfaction-rate/per-scope query this sprint's Analítica screen runs wants
 * it directly, exactly like every other per-scope query in this sprint).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->unique()->constrained('chat_messages')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->enum('rating', ['up', 'down']);
            $table->text('comment')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['employee_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_feedback');
    }
};
