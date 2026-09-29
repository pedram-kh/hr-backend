<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 13, build step 4 (plan.md §D.12): the five agent-engine escalation
 * reasons — `general_lane_blocked`, `profile_incomplete`,
 * `employee_requested_review`, `planner_escalated`, `tool_budget_exhausted`.
 * Same proven introspect-drop-readd idiom as every prior reason-CHECK
 * migration (`2026_09_11_120000_add_estatuto_fallback_gap_to_escalation_cards_reason.php`):
 * introspect the actual constraint name (never guessed), DROP ... IF EXISTS,
 * re-ADD with the full value set, with a working down(). Additive-only.
 */
return new class extends Migration
{
    /** Value set before this migration (after Sprint 10a's estatuto_fallback_gap add). */
    private const PRIOR = ['low_confidence', 'sensitive_topic', 'off_domain', 'explicit_request', 'conflict', 'salary_not_in_chat', 'salary_coverage_gap', 'reference_fact_coverage_gap', 'quality_sample_wrong', 'estatuto_fallback_gap'];

    /** Value set after this migration — the five agent-engine reasons appended. */
    private const CURRENT = [
        'low_confidence', 'sensitive_topic', 'off_domain', 'explicit_request', 'conflict', 'salary_not_in_chat',
        'salary_coverage_gap', 'reference_fact_coverage_gap', 'quality_sample_wrong', 'estatuto_fallback_gap',
        'general_lane_blocked', 'profile_incomplete', 'employee_requested_review', 'planner_escalated', 'tool_budget_exhausted',
    ];

    public function up(): void
    {
        $this->replaceReasonCheck(self::CURRENT);

        // Sprint 13 §D.13 — idempotency key for the employee's "¿Quieres que
        // lo revise RR. HH.?" button: a second tap on the same answered
        // message must return the EXISTING card, never create a second one.
        Schema::table('escalation_cards', function (Blueprint $table) {
            $table->unsignedBigInteger('reviewed_message_id')->nullable()->after('source_message_id');
        });

        // Partial unique index — only constrains rows with this reason, so it
        // never collides with any other reason's (nullable) source_message_id
        // reuse patterns.
        DB::statement(
            'CREATE UNIQUE INDEX escalation_cards_reviewed_message_unique '
            ."ON escalation_cards (reviewed_message_id) WHERE reason = 'employee_requested_review'"
        );

        // Sprint 13 §B.6.6 — the Guardarraíles general-lane toggle. Additive
        // nullable: NULL means "no admin override, env baseline governs"
        // (ADR-0019 raise-only: effective = env baseline AND (admin ?? true)).
        Schema::table('guardrail_config', function (Blueprint $table) {
            $table->boolean('general_lane_enabled')->nullable()->after('convert_allowed_reasons');
        });
    }

    public function down(): void
    {
        Schema::table('guardrail_config', function (Blueprint $table) {
            $table->dropColumn('general_lane_enabled');
        });

        DB::statement('DROP INDEX IF EXISTS escalation_cards_reviewed_message_unique');

        Schema::table('escalation_cards', function (Blueprint $table) {
            $table->dropColumn('reviewed_message_id');
        });

        $this->replaceReasonCheck(self::PRIOR);
    }

    /** @param  list<string>  $values */
    private function replaceReasonCheck(array $values): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return; // CHECK-constraint enum handling here is Postgres-specific.
        }

        $existing = DB::selectOne(
            'SELECT con.conname FROM pg_constraint con '
            .'JOIN pg_class rel ON rel.oid = con.conrelid '
            ."WHERE rel.relname = 'escalation_cards' AND con.contype = 'c' "
            ."AND pg_get_constraintdef(con.oid) LIKE '%reason%' LIMIT 1"
        );

        if ($existing !== null) {
            DB::statement('ALTER TABLE escalation_cards DROP CONSTRAINT IF EXISTS '.$existing->conname);
        }

        $list = implode(', ', array_map(fn ($v) => "'".$v."'", $values));
        DB::statement(
            'ALTER TABLE escalation_cards ADD CONSTRAINT escalation_cards_reason_check '
            ."CHECK (reason::text = ANY (ARRAY[$list]::text[]))"
        );
    }
};
