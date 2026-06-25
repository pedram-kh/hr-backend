<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 6 (additive, ADR-0019): the append-only audit log for every guardrail
 * config change — the same posture as `escalation_events` / `tag_events`
 * (created_at only, never UPDATE/DELETE-d). This is the "who tightened the bot's
 * caution, when, from what to what" record that makes the admin layer defensible
 * to the works-council / AEPD beside ADR-0018.
 *
 * `field` examples: retrieval_score_floor, answer_confidence_floor,
 * router_confidence_floor, off_domain_message, tone_constraints,
 * convert_allowed_reasons, blocked_topic_added, blocked_topic_disabled.
 *
 * A change that is REJECTED at the floor never reaches here — a rejected
 * below-floor POST writes no row (nothing changed). Acceptance proof (§4.4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guardrail_config_events', function (Blueprint $table) {
            $table->id();
            $table->string('field');
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['field', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guardrail_config_events');
    }
};
