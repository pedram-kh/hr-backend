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
 * Sprint 7f adds the two reference-fact SCOPE profiles (both deliberately with
 * NO job category): Hostelería Navarra (21) for the group-scoped case that must
 * escalate before Phase 3 and answer exactly after it, and Actividades
 * Deportivas Álava (2) for the convenio-wide Tier-3 case.
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

        // The two convenios bound to an OCR'd scan after Sprint 7e: the Navarra
        // gestión deportiva successor (a full convenio text) and the estatal
        // instalaciones deportivas Art. 22 amendment (a partial_agreement whose
        // base convenio text is NOT in the corpus — this profile is how we see
        // what an amendment-only convenio can and cannot answer).
        $this->seedEmployee(
            email: 'test-deporte-navarra@example.com',
            name: 'Test Gestión Deportiva Navarra (convenio 20)',
            convenio: $this->byNumero('31008235012003') ?? $this->byName('DEPORTIVA', 'Navarra'),
        );

        $this->seedEmployee(
            email: 'test-deporte-estatal@example.com',
            name: 'Test Instalaciones Deportivas Estatal (convenio 9)',
            convenio: $this->byNumero('99015105012005') ?? $this->byName('INSTALACIONES DEPORTIVAS', 'Estatal'),
        );

        // Sprint 7f — the two reference-fact scope profiles.
        //
        // Hostelería Navarra (convenio 21) is THE 7f case: the convenio splits
        // Grupo 2 into `área 5` (90/75/60 días) and `resto áreas` (60/45/30), a
        // distinction that lived only as prose inside `group_label` until 7f made
        // it structured. It has ZERO `convenio_job_categories` (no salary .xlsx
        // ever minted any), so this employee CANNOT resolve a group through
        // `job_category.group_code` — which is precisely the point:
        //  - before Phase 3 it is the group-less baseline: a verified group-scoped
        //    fact must ESCALATE `reference_fact_coverage_gap`, never guess;
        //  - after Phase 3 it is the live proof, once an admin sets its structured
        //    group to Grupo 2 › resto áreas (60/45/30) or Grupo 1 (90/75/60).
        // `resolveCategory: false` is explicit rather than incidental: nothing may
        // quietly bind a category here later and make the group resolvable again.
        $this->seedEmployee(
            email: 'test-hosteleria-navarra@example.com',
            name: 'Test Hostelería Navarra (convenio 21)',
            convenio: $this->byNumero('31003805011981') ?? $this->byName('HOSTELERIA', 'Navarra'),
            jobCategoryId: null,
            resolveCategory: false,
        );

        // Actividades Deportivas Álava (convenio 2) carries a CONVENIO-WIDE periodo
        // de prueba fact (no group, no category — "no podrá exceder de dos meses en
        // ningún caso"). It is the Tier-3 counterpart to the profile above: the
        // check that a convenio-wide verified fact still answers `path:
        // reference_fact` for an employee with no group at all.
        $this->seedEmployee(
            email: 'test-deportivas-alava@example.com',
            name: 'Test Actividades Deportivas Álava (convenio 2)',
            convenio: $this->byNumero('01003205012006') ?? $this->byName('ACTIVIDADES DEPORTIVAS', 'lava'),
            jobCategoryId: null,
            resolveCategory: false,
        );

        // Sprint 10a (ADR-0032) — the two sides of the Estatuto fallback trigger.
        //
        // Acción e Intervención Social Estatal (convenio 7) is the FULL GAP: no
        // prose document of any retrieval status, no chunks of any status, and —
        // checked deliberately, because either would short-circuit the prose turn
        // before the fallback is reached — no salary table and no reference fact.
        // It is the only non-fixture convenio in the corpus that satisfies D3's
        // predicate, so it is what `never_ingested` means in practice.
        //
        // NOTE for whoever reads the sprint doc: the build authorization's D5 named
        // convenio 16 here. That was chosen against the plan's looser predicate
        // (zero ACTIVE prose docs); D3 tightened it to any status, and convenio 16
        // has a historical text — so it lands on the other side of the split and is
        // seeded below as a negative control instead.
        $this->seedEmployee(
            email: 'test-fullgap@example.com',
            name: 'Test Acción e Intervención Social Estatal (convenio 7)',
            convenio: $this->byNumero('99016085012007') ?? $this->byName('INTERVENCI', 'Estatal'),
            jobCategoryId: null,
            resolveCategory: false,
        );

        // Hostelería Huesca (convenio 16) is the D3 MID-INGEST case, and the only
        // real one in the corpus: its single prose document (a historical convenio
        // text) has zero chunks, so there is nothing to retrieve — but a document
        // DOES exist, so the Estatuto must not answer in its place. Fails closed to
        // `estatuto_fallback_gap`. Until now this shape existed only as a test
        // fixture; this profile is how it gets exercised against real data.
        $this->seedEmployee(
            email: 'test-midingest@example.com',
            name: 'Test Hostelería Huesca (convenio 16, sin fragmentos)',
            convenio: $this->byNumero('22000175012004') ?? $this->byName('HOSTELERIA', 'Huesca'),
            jobCategoryId: null,
            resolveCategory: false,
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
