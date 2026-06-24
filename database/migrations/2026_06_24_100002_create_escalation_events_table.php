<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 4 (additive): the append-only activity/audit log for an escalation
 * card. Every status move, assignment, agent reply, resolution, knowledge
 * conversion, and blocked publish is recorded here — never an UPDATE/DELETE of
 * history (the same append-only posture as `tag_events`).
 *
 * Kept DISTINCT from `tag_events` (resolved Q-E): `tag_events.facet` is a
 * document-vocabulary facet; overloading it for card activity would muddy both
 * timelines. This table reads cleanly as "what happened to this card."
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('escalation_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('escalation_card_id')->constrained('escalation_cards')->cascadeOnDelete();
            // status_change | assigned | replied | resolved | converted | publish_blocked
            $table->string('type');
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['escalation_card_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('escalation_events');
    }
};
