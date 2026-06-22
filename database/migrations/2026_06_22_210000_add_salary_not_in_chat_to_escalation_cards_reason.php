<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Correction-02 (Sprint 2b-1): add the `salary_not_in_chat` escalation reason.
 *
 * The salary-topic guard escalates salary/wage questions with this distinct
 * reason. The `escalation_cards.reason` column is enforced by a Postgres CHECK
 * constraint (Laravel `enum`), so we drop and re-add it with the complete value
 * set. Additive-only and idempotent: safe on fresh installs, already-migrated
 * environments, and the dev DB (replacing the manual ALTER applied during the
 * fix). The historical create migration is left untouched (committed migrations
 * are immutable).
 */
return new class extends Migration
{
    /** Committed value set, before Correction-02. */
    private const PRIOR = ['low_confidence', 'sensitive_topic', 'off_domain', 'explicit_request', 'conflict'];

    /** Value set after Correction-02. */
    private const CURRENT = ['low_confidence', 'sensitive_topic', 'off_domain', 'explicit_request', 'conflict', 'salary_not_in_chat'];

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

        // Introspect the actual constraint name governing the `reason` column.
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
