<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sprint 8, Step 6 (plan.md §6.4, §11 item 6): add the `quality_sample_wrong`
 * escalation reason — created when a `quality_samples` row is reviewed with
 * `verdict='wrong'`. Same proven introspect-drop-readd idiom as the four
 * prior reason-CHECK migrations (`salary_not_in_chat`, `salary_coverage_gap`,
 * `reference_fact_coverage_gap`): introspect the actual constraint name
 * (never guessed), DROP ... IF EXISTS, re-ADD with the full value set, with a
 * working down(). Additive-only; committed predecessor migrations untouched.
 */
return new class extends Migration
{
    /** Value set before this migration (after Sprint 7c's reference_fact_coverage_gap add). */
    private const PRIOR = ['low_confidence', 'sensitive_topic', 'off_domain', 'explicit_request', 'conflict', 'salary_not_in_chat', 'salary_coverage_gap', 'reference_fact_coverage_gap'];

    /** Value set after this migration — `quality_sample_wrong` appended. */
    private const CURRENT = ['low_confidence', 'sensitive_topic', 'off_domain', 'explicit_request', 'conflict', 'salary_not_in_chat', 'salary_coverage_gap', 'reference_fact_coverage_gap', 'quality_sample_wrong'];

    public function up(): void
    {
        $this->replaceReasonCheck(self::CURRENT);
    }

    public function down(): void
    {
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
