<?php

namespace Database\Seeders;

use App\Models\Territory;
use App\Support\TerritoryCatalog;
use Illuminate\Database\Seeder;

/**
 * Seeds the canonical territorial vocabulary (the 12 scopes present in Sedena's
 * registry) with `level` and curated aliases. The registry import (`registry:import`)
 * is the authoritative source and re-confirms/enriches these rows; this seeder
 * gives a fresh install / test DB a valid controlled vocabulary up front.
 */
class TerritorySeeder extends Seeder
{
    public function run(): void
    {
        foreach (TerritoryCatalog::all() as $t) {
            Territory::updateOrCreate(
                ['level' => $t['level'], 'code' => $t['code']],
                ['name' => $t['name'], 'aliases' => $t['aliases']],
            );
        }
    }
}
