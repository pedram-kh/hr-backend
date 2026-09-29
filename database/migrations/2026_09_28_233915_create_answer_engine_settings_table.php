<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 13, build step 2 (plan.md §E.15 step 2, §F.14). The engine switch's
 * runtime override: `HR_ANSWER_ENGINE` (default `classic`) is the env
 * baseline, but flipping an env var needs a config rebuild + container
 * restart on staging — no good for a same-day CP-1 walkthrough or a fast
 * rollback. This single-row table (id = 1, same shape as
 * `answer_model_settings`, ADR-0015) lets a super_admin override the
 * effective engine per turn without a deploy, via `php artisan
 * answer-engine:set`. `engine IS NULL` means "no override — use the env
 * default", not "classic" — so clearing the override and explicitly setting
 * `classic` are different, auditable actions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('answer_engine_settings', function (Blueprint $table) {
            $table->id();
            // NULL = no override, fall back to config('hr.answer_engine') (the
            // HR_ANSWER_ENGINE env baseline). Not a DB CHECK constraint — kept
            // to the two known engine names in AnswerEngineDispatcher/PHP,
            // mirroring how `escalation_cards.reason` is CHECK-enforced but this
            // narrower, admin-only, single-row config knob is not (same
            // reasoning as `guardrail_config`'s boolean/enum columns).
            $table->string('engine')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('answer_engine_settings');
    }
};
