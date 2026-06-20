<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Closed vocabulary of document types (data-model §4).
 */
class DocumentTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            ['code' => 'convenio_text', 'name' => 'Convenio (texto)'],
            ['code' => 'salary_tables', 'name' => 'Tablas salariales'],
            ['code' => 'changes', 'name' => 'Cambios'],
            ['code' => 'partial_agreement', 'name' => 'Acuerdo Parcial'],
            ['code' => 'summary', 'name' => 'Resumen'],
            ['code' => 'national_law', 'name' => 'Estatuto / Ley nacional'],
            ['code' => 'internal_hr_ruling', 'name' => 'Resolución interna de RRHH'],
            ['code' => 'other', 'name' => 'Otro'],
        ];

        foreach ($types as $type) {
            DB::table('document_types')->updateOrInsert(
                ['code' => $type['code']],
                ['name' => $type['name'], 'updated_at' => now(), 'created_at' => now()],
            );
        }
    }
}
