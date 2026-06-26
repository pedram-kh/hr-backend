<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sprint 7b-1 (additive, data — ADR-0021) — seed the `reference_source`
 * document_type code so a prod `migrate` and a fresh install both get it
 * (DocumentTypeSeeder is updated in lockstep for fresh seeds; this migration
 * covers already-migrated databases). Idempotent: updateOrInsert keyed on `code`.
 *
 * `reference_source` is the DELIBERATE routing tag (Invariant 2 / Q5): a
 * non-salary .docx/.xlsx tagged this way feeds the manual reference-fact path and
 * is structurally ignored by `salary:import` (which filters document_type =
 * salary_tables). The closed document_type vocabulary grows only by this kind of
 * deliberate admin action — never by the AI or as a side effect of tagging.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('document_types')->updateOrInsert(
            ['code' => 'reference_source'],
            ['name' => 'Fuente de referencia estructurada', 'updated_at' => now(), 'created_at' => now()],
        );
    }

    public function down(): void
    {
        DB::table('document_types')->where('code', 'reference_source')->delete();
    }
};
