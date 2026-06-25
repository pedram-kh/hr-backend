<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 6 (additive, ADR-0019): the admin ADD-ONLY blocked-topic / off-domain
 * list. One row per admin-added trigger.
 *
 * ADD-ONLY / UNION: this table holds ONLY admin-added triggers. The hardcoded
 * GuardrailService baseline (acoso / salud mental / despido / legal-médico /
 * other-employee) is NOT here and is not editable — so a baseline pattern can
 * never be removed (it is code, not data). The engine escalates when the
 * baseline fires OR an enabled row here matches (a pure union, applied at the
 * SAME pre-router point as the baseline so a blocked question never reaches
 * hr-ai). "Disable" is a SOFT flag (never a hard delete) so history is intact.
 *
 * `kind`:
 *  - blocked_topic → escalate `sensitive_topic` (knob 2)
 *  - off_domain    → escalate `off_domain`      (knob 3, narrow-only boundary)
 *
 * MATCHING (required, §7.6): `pattern` is matched as a normalized,
 * accent-insensitive, word-boundary LITERAL — NEVER as raw admin-supplied regex
 * (a raw-regex field is a ReDoS risk AND a weakening vector). GuardrailPolicy
 * escapes the literal before matching.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guardrail_blocked_topics', function (Blueprint $table) {
            $table->id();
            // The admin keyword/phrase, stored verbatim; matched as an escaped,
            // accent-insensitive, word-boundary literal (never compiled as regex).
            $table->string('pattern');
            // blocked_topic | off_domain
            $table->string('kind')->default('blocked_topic');
            $table->boolean('enabled')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->foreignId('disabled_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('disabled_at')->nullable();
            $table->timestamps();

            $table->index(['enabled', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guardrail_blocked_topics');
    }
};
