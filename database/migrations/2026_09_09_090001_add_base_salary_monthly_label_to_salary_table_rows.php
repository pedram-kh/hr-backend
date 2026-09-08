<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Correction-salary-01 follow-up: the monthly figure carries the header it was
 * read from.
 *
 * `base_salary_monthly` is a single typed column, but the sources do not print a
 * single monthly quantity. Convenio 10's sheet prints BOTH a `salario base` of
 * 1.183,34 and a `bruto mes` of 1.771,64 (base + prorated extras); COEAS Navarra
 * prints `14 pagas` and `12 pagas` side by side. Calling any of them "salario
 * mensual" in an answer states a quantity the employee cannot check against a
 * payslip. The verbatim header travels with the figure so the answer can name it
 * the way the table names it.
 *
 * Nullable and unbackfilled on purpose: a label can only come from a re-import
 * (it is source text, not something this migration may invent). Rows without one
 * keep the previous, narrower wording until their document is re-imported.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salary_table_rows', function (Blueprint $table) {
            $table->string('base_salary_monthly_label')->nullable()->after('base_salary_monthly');
        });
    }

    public function down(): void
    {
        Schema::table('salary_table_rows', function (Blueprint $table) {
            $table->dropColumn('base_salary_monthly_label');
        });
    }
};
