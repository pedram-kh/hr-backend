<?php

namespace Database\Seeders;

use App\Models\Convenio;
use App\Models\Employee;
use App\Models\SalaryTable;
use App\Models\SalaryTableRow;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

/**
 * Dev-only seeded employee profiles for the Sprint 2b-1 + 2b-2 eyes-on gates (the
 * directory UI is Sprint 5; roadmap notes test users suffice until then).
 *
 * NEVER committed corpus/secret data — placeholder identities the tester controls,
 * bound to REAL convenios resolved from the registry import (`registry:import`) +
 * embedded chunks (`chunks:embed`) + salary import (`salary:import`). If a target
 * convenio isn't present yet, that employee is skipped with a log line.
 *
 * Sprint 2b-2 adds salary-path coverage:
 *  - a SALARY-ANSWERABLE profile (COEAS Andalucía) WITH a job_category that has an
 *    imported salary row → the exact SQL salary answer (year-aligned, the Q5
 *    antithesis);
 *  - a NO-CATEGORY profile on the same convenio → exercises the constrained pick;
 *  - the COVERAGE-GAP profile (Gipuzkoa Limpieza — salary is PDF-only) → escalate
 *    salary_coverage_gap.
 *
 * Correction-salary-01 adds the two convenios whose salary rows came from a
 * converted PDF grid (15 and 3): one whose source prints a monthly, one whose
 * source prints only an annual — the two answer shapes ADR-0027 distinguishes.
 *
 * The super_admin used by the "Answer model" key screen is seeded by
 * TestUserSeeder (admin@example.com).
 */
class ChatTestUserSeeder extends Seeder
{
    public function run(): void
    {
        // Coverage-gap salary profile + bilingual prose gold test (Gipuzkoa).
        $this->seedEmployee(
            email: 'test-gipuzkoa@example.com',
            name: 'Test Gipuzkoa (Limpieza)',
            convenio: $this->byNumero('20000785011981') ?? $this->byName('LIMPIEZA', 'Gipuzkoa'),
        );

        // Prose gold tests (periodo de prueba, trabajo a distancia) + Q10 compound.
        $this->seedEmployee(
            email: 'test-navarra@example.com',
            name: 'Test Navarra (Limpieza)',
            convenio: $this->byName('LIMPIEZA', 'Navarra'),
        );

        // Salary-answerable: COEAS Andalucía has imported xlsx salary_tables. Bind a
        // category that actually has a salary row so the gold salary check returns
        // the exact typed figure for the resolved year (the Q5 year-alignment test).
        $andalucia = $this->byNumero('71103505012022') ?? $this->byName('OCIO', 'Andaluc');
        $this->seedEmployee(
            email: 'test-andalucia@example.com',
            name: 'Test Andalucía (COEAS)',
            convenio: $andalucia,
            jobCategoryId: $this->categoryWithSalaryRow($andalucia),
        );

        // No-category profile on the SAME convenio → triggers the constrained pick
        // (then a "según tu indicación"-labelled answer once a category is picked).
        $this->seedEmployee(
            email: 'test-andalucia-nocat@example.com',
            name: 'Test Andalucía (sin categoría)',
            convenio: $andalucia,
            jobCategoryId: null,
            resolveCategory: false,
        );

        // The two convenios whose salary figures exist ONLY because a PDF grid was
        // converted (flow 2b) — the profiles that exercise that path end to end.
        // They also cover both ADR-0027 answer shapes: convenio 15's gazette prints
        // a monthly next to its annual, convenio 3's prints an annual and nothing
        // else, so its answer states the annual alone rather than deriving one.
        $gestores = $this->byNumero('20104415012022') ?? $this->byName('INFORMACI', 'Gipuzkoa');
        $this->seedEmployee(
            email: 'test-gestores-gipuzkoa@example.com',
            name: 'Test Gestores Gipuzkoa (convenio 15)',
            convenio: $gestores,
            jobCategoryId: $this->categoryWithSalaryRow($gestores),
        );

        $ocioAlava = $this->byNumero('01100635012017') ?? $this->byName('OCIO EDUCATIVO', 'lava');
        $this->seedEmployee(
            email: 'test-ocio-alava@example.com',
            name: 'Test Ocio Educativo Álava (convenio 3)',
            convenio: $ocioAlava,
            jobCategoryId: $this->categoryWithSalaryRow($ocioAlava),
        );

        // A generic active-convenio employee for the sensitive-topic + floor gates.
        $this->seedEmployee(
            email: 'test-any@example.com',
            name: 'Test Any',
            convenio: $this->byNumero('20000785011981') ?? Convenio::query()->first(),
        );
    }

    /**
     * @param  int|null  $jobCategoryId  explicit category to bind (overrides resolution)
     * @param  bool  $resolveCategory  when no explicit id, bind the convenio's first category (false → leave null)
     */
    private function seedEmployee(string $email, string $name, ?Convenio $convenio, ?int $jobCategoryId = null, bool $resolveCategory = true): void
    {
        if (! $convenio) {
            Log::warning("ChatTestUserSeeder: no convenio for {$email} — run registry:import + chunks:embed first; skipped.");

            return;
        }

        $convenio->loadMissing('territory');

        $categoryId = $jobCategoryId;
        if ($categoryId === null && $resolveCategory) {
            $categoryId = $convenio->jobCategories()->first()?->id;
        }

        Employee::updateOrCreate(
            ['email' => $email],
            [
                'full_name' => $name,
                'convenio_id' => $convenio->id,
                'job_category_id' => $categoryId,
                'territory_id' => $convenio->territory_id,
                'employment_type' => 'full_time',
                'status' => 'active',
            ],
        );
    }

    /** The id of a job category that has at least one imported salary row for the convenio. */
    private function categoryWithSalaryRow(?Convenio $convenio): ?int
    {
        if (! $convenio) {
            return null;
        }
        $tableIds = SalaryTable::where('convenio_id', $convenio->id)->pluck('id');
        if ($tableIds->isEmpty()) {
            return null; // no salary tables (coverage gap) — fall back to first category
        }

        return SalaryTableRow::whereIn('salary_table_id', $tableIds)
            ->whereNotNull('job_category_id')
            ->value('job_category_id');
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
