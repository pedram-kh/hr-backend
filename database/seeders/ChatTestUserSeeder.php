<?php

namespace Database\Seeders;

use App\Models\Convenio;
use App\Models\Employee;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

/**
 * Dev-only seeded employee profiles for the Sprint 2b-1 eyes-on gates (the
 * directory UI is Sprint 5; roadmap notes test users suffice until then).
 *
 * NEVER committed corpus/secret data — these are placeholder identities the
 * tester controls, bound to REAL convenios resolved from the registry import
 * (`registry:import`) + embedded chunks (`chunks:embed`). If a target convenio
 * isn't present yet, that employee is skipped with a log line (run the imports
 * first) rather than failing the whole seed.
 *
 * The super_admin used by the "Answer model" key screen is seeded by
 * TestUserSeeder (admin@example.com).
 */
class ChatTestUserSeeder extends Seeder
{
    public function run(): void
    {
        // [email, convenio matcher]
        $this->seedEmployee(
            email: 'test-gipuzkoa@example.com',
            name: 'Test Gipuzkoa (Limpieza)',
            convenio: $this->byNumero('20000785011981') ?? $this->byName('LIMPIEZA', 'Gipuzkoa'),
        );

        $this->seedEmployee(
            email: 'test-navarra@example.com',
            name: 'Test Navarra (Limpieza)',
            convenio: $this->byName('LIMPIEZA', 'Navarra'),
        );

        $this->seedEmployee(
            email: 'test-andalucia@example.com',
            name: 'Test Andalucía (COEAS)',
            convenio: $this->byNumero('71103505012022') ?? $this->byName('OCIO', 'Andaluc'),
        );

        // A generic active-convenio employee for the sensitive-topic + floor gates.
        // Falls back to any convenio so these gates always have a working profile.
        $this->seedEmployee(
            email: 'test-any@example.com',
            name: 'Test Any',
            convenio: $this->byNumero('20000785011981') ?? Convenio::query()->first(),
        );
    }

    private function seedEmployee(string $email, string $name, ?Convenio $convenio): void
    {
        if (! $convenio) {
            Log::warning("ChatTestUserSeeder: no convenio for {$email} — run registry:import + chunks:embed first; skipped.");

            return;
        }

        $convenio->loadMissing('territory');
        $jobCategory = $convenio->jobCategories()->first();

        Employee::updateOrCreate(
            ['email' => $email],
            [
                'full_name' => $name,
                'convenio_id' => $convenio->id,
                'job_category_id' => $jobCategory?->id,
                'territory_id' => $convenio->territory_id,
                'employment_type' => 'full_time',
                'status' => 'active',
            ],
        );
    }

    private function byNumero(string $numero): ?Convenio
    {
        return Convenio::where('numero', $numero)->first();
    }

    private function byName(string $needle, string $territoryOrName): ?Convenio
    {
        return Convenio::where('name', 'ilike', "%{$needle}%")
            ->where(function ($q) use ($territoryOrName) {
                $q->where('name', 'ilike', "%{$territoryOrName}%")
                    ->orWhereHas('territory', fn ($t) => $t->where('name', 'ilike', "%{$territoryOrName}%"));
            })
            ->first();
    }
}
