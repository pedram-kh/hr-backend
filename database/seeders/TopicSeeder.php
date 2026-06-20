<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Managed vocabulary seeded from the FAQ sheet's 11 categories (data-model §4,
 * glossary). All seeded as `approved`; the AI may later propose new topics.
 */
class TopicSeeder extends Seeder
{
    public function run(): void
    {
        $topics = [
            'jornada',
            'vacaciones',
            'festivos',
            'permisos retribuidos',
            'bajas médicas',
            'conciliación',
            'excedencias',
            'retribución',
            'permisos no retribuidos',
            'normativa/derechos',
            'formación',
        ];

        foreach ($topics as $name) {
            DB::table('topics')->updateOrInsert(
                ['name' => $name],
                [
                    'status' => 'approved',
                    'proposed_by' => null,
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
        }
    }
}
