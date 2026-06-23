<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sprint 2b-2: add the `salary_coverage_gap` escalation reason.
 *
 * Salary is now answered IN CHAT from the structured `salary_tables` via SQL
 * (ADR-0006). When the convenio/year/category has no salary row (a genuine 2a
 * coverage gap, ADR-0014), the salary path escalates honestly with this new,
 * distinct reason — so the trace/analytics say "salary deferred for a coverage
 * gap" rather than the Correction-02 `salary_not_in_chat`, which would now LIE
 * (after this sprint salary IS in chat). `salary_not_in_chat` is RETIRED — it is
 * no longer emitted by any code path — but the value is LEFT in the enum so the
 * CHECK constraint still accepts historical rows written in 2b-1.
 *
 * Same Correction-02 pattern: introspect the actual CHECK constraint name (not
 * guessed), DROP ... IF EXISTS, re-ADD with the full value set, with a working
 * down(). Additive-only and idempotent. The historical create + Correction-02
 * migrations are left untouched (committed migrations are immutable).
 */
return new class extends Migration
{
    /** Value set before this migration (after Correction-02). */
    private const PRIOR = ['low_confidence', 'sensitive_topic', 'off_domain', 'explicit_request', 'conflict', 'salary_not_in_chat'];

    /**
     * Value set after this migration. `salary_not_in_chat` is kept (historical
     * rows) even though it is no longer emitted; `salary_coverage_gap` is added.
     */
    private const CURRENT = ['low_confidence', 'sensitive_topic', 'off_domain', 'explicit_request', 'conflict', 'salary_not_in_chat', 'salary_coverage_gap'];

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
