<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Correction-salary-01 — `num_payments` → `pagas_count`.
 *
 * The column used to mean "the divisor hr-ai used to compute
 * base_salary_monthly", and was set to a constant 14 for every row that had an
 * annual figure, whatever the source said. It now means "the payment count the
 * SOURCE states": NULL when the source states none, and never used to derive
 * anything (ADR-0006). The rename is deliberate — the old name invited the old
 * behaviour back.
 *
 * The old VALUES are not touched here on purpose. Every stored
 * `base_salary_monthly` was `gross_annual / 14` and every `num_payments` was a
 * constant 14, but wiping them in a migration would also erase the evidence the
 * Correction-salary-01 audit has to report (which convenio was told what). The
 * sequence is: migrate → `salary:audit-monthly` (reports the discrepancies) →
 * `salary:import` (which deletes and rewrites every row of the tables it
 * imports, so nothing derived survives) → `salary:audit-monthly` again, which
 * fails if any unsourced leftover remains. Nothing is lost either way:
 * `raw_values` holds every original figure verbatim.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salary_table_rows', function (Blueprint $table) {
            $table->renameColumn('num_payments', 'pagas_count');
        });
    }

    public function down(): void
    {
        Schema::table('salary_table_rows', function (Blueprint $table) {
            $table->renameColumn('pagas_count', 'num_payments');
        });
    }
};
