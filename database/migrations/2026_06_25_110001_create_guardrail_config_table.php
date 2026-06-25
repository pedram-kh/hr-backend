<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 6 (additive, ADR-0019): the admin-configurable guardrail layer becomes
 * DATA for the first time. Single-row, GLOBAL config (id = 1) — the same proven
 * shape as `answer_model_settings` (typed columns, not a key/value bag, so each
 * knob carries its type and is validated against its HARDCODED floor server-side).
 *
 * RAISE-ONLY INVARIANT: the threshold columns are NULLABLE. `null` means "use the
 * hardcoded floor in config/hr.php"; a non-null value is an admin OVERRIDE that
 * the server only accepts when it is >= the hardcoded floor (rejected, never
 * clamped — StoreGuardrailConfigRequest). The engine reads max(baseline, admin)
 * via GuardrailPolicy, so a stored value can never weaken the baseline. The
 * hardcoded GuardrailService patterns are NOT here — they stay code, uneditable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guardrail_config', function (Blueprint $table) {
            $table->id(); // always row id = 1 (GuardrailConfig::current())

            // Check A (load-bearing) admin override. null = use config/hr.php floor.
            // Server rejects a value below config('hr.retrieval_score_floor').
            $table->decimal('retrieval_score_floor', 4, 3)->nullable();

            // Check C (tiebreaker, NOT a primary gate) admin override. null = floor.
            // Server rejects a value below config('hr.answer_confidence_floor').
            $table->decimal('answer_confidence_floor', 4, 3)->nullable();

            // Router confidence floor (secondary knob). null = floor.
            // Server rejects a value below config('hr.router_confidence_floor').
            $table->decimal('router_confidence_floor', 4, 3)->nullable();

            // Off-domain refusal copy surfaced to the employee. null = the default
            // ChatService::ESCALATION_MESSAGE. Display text only — changes NO gate.
            $table->text('off_domain_message')->nullable();

            // Style-only tone guidance prepended (synthesis-LOCAL) to the question
            // sent to /synthesise. Sanitized + length-capped server-side; it can
            // never instruct the model past a gate (gates are downstream of tone).
            $table->text('tone_constraints')->nullable();

            // Convert-by-reason ALLOW-set (restrict-only). null = the hardcoded
            // baseline allow-set. The effective set is INTERSECTION(baseline, admin)
            // — admins can only remove reasons; sensitive_topic is never in the
            // baseline set, so it is never convertible.
            $table->jsonb('convert_allowed_reasons')->nullable();

            $table->foreignId('updated_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guardrail_config');
    }
};
