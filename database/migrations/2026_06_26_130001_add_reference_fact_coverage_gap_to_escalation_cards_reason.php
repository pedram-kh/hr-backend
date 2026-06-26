<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sprint 7c (Phase 1, ADR-0023): add the `reference_fact_coverage_gap` escalation
 * reason.
 *
 * Verified reference facts are now answerable in chat (the salary-parallel
 * routed path). When the asker's resolved scope has NO verified, in-scope,
 * in-validity, topic-matching fact — or only an unverified / out-of-validity /
 * future-only / unresolvable-group fact — the reference-fact path escalates
 * honestly with this distinct reason (the `salary_coverage_gap` precedent), so
 * the trace/analytics say "deferred for a reference-fact coverage gap" rather
 * than the generic `low_confidence`. Only a `verified` fact is ever quoted
 * (ADR-0020/0021 — the inert-until-verified spine).
 *
 * Same proven idiom as `add_salary_coverage_gap`: introspect the actual CHECK
 * constraint name (never guessed), DROP ... IF EXISTS, re-ADD with the FULL
 * value set, with a working down(). The ONLY schema migration in Sprint 7c
 * (Phase 2 reuses `conflict`/`low_confidence` — no new reason). Additive-only
 * and idempotent; committed predecessor migrations are left untouched.
 */
return new class extends Migration
{
    /** Value set before this migration (after Sprint 2b-2's salary_coverage_gap add). */
    private const PRIOR = ['low_confidence', 'sensitive_topic', 'off_domain', 'explicit_request', 'conflict', 'salary_not_in_chat', 'salary_coverage_gap'];

    /** Value set after this migration — `reference_fact_coverage_gap` appended. */
    private const CURRENT = ['low_confidence', 'sensitive_topic', 'off_domain', 'explicit_request', 'conflict', 'salary_not_in_chat', 'salary_coverage_gap', 'reference_fact_coverage_gap'];

    public function up(): void
    {
        $this->replaceReasonCheck(self::CURRENT);
    }

    public function down(): void
    {
        $this->replaceReasonCheck(self::PRIOR);
    }

    /**
     * Drop whatever CHECK constraint currently governs escalation_cards.reason
     * (introspected, not guessed) and re-add it with the given value set under
     * the canonical name.
     *
     * @param  list<string>  $values
     */
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
