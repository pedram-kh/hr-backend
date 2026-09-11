<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sprint 10a, M1 (build step 6, ADR-0032): add the `estatuto_fallback_gap`
 * escalation reason.
 *
 * Raised when an employee's convenio has no retrievable prose AND the Estatuto
 * fallback is NOT allowed to fire — i.e. a convenio text exists in the system
 * but is not being served (expired with no successor, mid-ingest, tagging under
 * review, or a scan with no text). An expired convenio generally remains in
 * force under ultraactividad (ET art. 86.4), so answering it from the national
 * baseline would be confidently wrong in the direction that carries legal
 * weight. The turn escalates to a human instead, and the card says which of the
 * four causes it is so HR knows what to fix.
 *
 * Same proven introspect-drop-readd idiom as the five prior reason-CHECK
 * migrations: introspect the actual constraint name (never guessed), DROP ...
 * IF EXISTS, re-ADD with the full value set, with a working down().
 * Additive-only; committed predecessor migrations untouched.
 */
return new class extends Migration
{
    /** Value set before this migration (after Sprint 8's quality_sample_wrong add). */
    private const PRIOR = ['low_confidence', 'sensitive_topic', 'off_domain', 'explicit_request', 'conflict', 'salary_not_in_chat', 'salary_coverage_gap', 'reference_fact_coverage_gap', 'quality_sample_wrong'];

    /** Value set after this migration — `estatuto_fallback_gap` appended. */
    private const CURRENT = ['low_confidence', 'sensitive_topic', 'off_domain', 'explicit_request', 'conflict', 'salary_not_in_chat', 'salary_coverage_gap', 'reference_fact_coverage_gap', 'quality_sample_wrong', 'estatuto_fallback_gap'];

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
