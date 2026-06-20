<?php

namespace Database\Seeders;

use App\Models\Province;
use Illuminate\Database\Seeder;

/**
 * Review C3: seed ONLY the territorial scopes present in Sedena's actual data,
 * using the 2-digit numero prefix as `code` (vocabulary derived from the
 * registry — ADR-0002). The full Spanish provincial set is NOT seeded.
 *
 * Note: Andalucía is an autonomous community, not a single 2-digit province;
 * its code is recorded as it appears in the source data and re-confirmed at
 * the Sprint 1 registry import (flagged in plan.md Q2).
 */
class ProvinceSeeder extends Seeder
{
    public function run(): void
    {
        $provinces = [
            ['code' => '01', 'name' => 'Álava', 'aliases' => ['Araba', 'ALABA'], 'is_national' => false],
            ['code' => '20', 'name' => 'Gipuzkoa', 'aliases' => ['Guipúzcoa'], 'is_national' => false],
            ['code' => '22', 'name' => 'Huesca', 'aliases' => [], 'is_national' => false],
            ['code' => '28', 'name' => 'Madrid', 'aliases' => [], 'is_national' => false],
            ['code' => '31', 'name' => 'Navarra', 'aliases' => ['Nafarroa'], 'is_national' => false],
            ['code' => '33', 'name' => 'Asturias', 'aliases' => ['Principado de Asturias'], 'is_national' => false],
            ['code' => '37', 'name' => 'Salamanca', 'aliases' => [], 'is_national' => false],
            ['code' => '39', 'name' => 'Cantabria', 'aliases' => [], 'is_national' => false],
            ['code' => '46', 'name' => 'Valencia', 'aliases' => ['València'], 'is_national' => false],
            ['code' => '48', 'name' => 'Vizcaya', 'aliases' => ['Bizkaia'], 'is_national' => false],
            ['code' => 'AN', 'name' => 'Andalucía', 'aliases' => ['Andalucia'], 'is_national' => false],
            ['code' => '99', 'name' => 'Estatal', 'aliases' => ['Nacional', 'Estatal'], 'is_national' => true],
        ];

        foreach ($provinces as $province) {
            Province::updateOrCreate(
                ['code' => $province['code']],
                [
                    'name' => $province['name'],
                    'aliases' => $province['aliases'],
                    'is_national' => $province['is_national'],
                ],
            );
        }
    }
}
