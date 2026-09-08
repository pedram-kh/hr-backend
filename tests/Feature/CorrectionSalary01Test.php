<?php

namespace Tests\Feature;

use App\Models\Convenio;
use App\Models\ConvenioJobCategory;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Employee;
use App\Models\SalaryTable;
use App\Models\SalaryTableRow;
use App\Models\Sector;
use App\Models\Territory;
use App\Services\ExtractionClient;
use App\Services\SalaryAnswerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Correction-salary-01 — a salary figure is a SOURCE CELL or it is not stored.
 *
 * hr-ai used to compute `base_salary_monthly = gross_annual / 14` and assert
 * `num_payments = 14` for every table. Convenio 15 (Gestores Información
 * Gipuzkoa) pays its annual over 15, so the chat read out 2.392,24 € where the
 * convenio's own gazette prints 2.232,75 €. This test class pins the three
 * halves of the fix:
 *
 *  1. the ANSWER states only stored figures — no monthly is invented, and a
 *     stated pagas count is reported but never applied as a divisor;
 *  2. `salary:import` FAILS LOUDLY (non-zero, no success line, nothing
 *     written) when a recognized grid yields no rows or maps to no typed
 *     field, instead of reporting a completed import over nothing;
 *  3. `salary:audit-monthly` catches a stored monthly that contradicts (or has
 *     no) source cell — the guard that keeps a derived figure from creeping
 *     back in.
 *
 * hr-ai's own half of the contract is pinned in
 * `hr-ai/scripts/salary_parser_test.py`.
 */
class CorrectionSalary01Test extends TestCase
{
    use RefreshDatabase;

    private Convenio $convenio;

    private Territory $territory;

    private ConvenioJobCategory $category;

    private SalaryTable $table;

    private Document $salaryDoc;

    protected function setUp(): void
    {
        parent::setUp();

        $this->territory = Territory::create(['code' => '20', 'name' => 'Gipuzkoa', 'level' => 'provincial', 'aliases' => []]);
        $sector = Sector::create(['name' => 'Información y Documentación', 'aliases' => []]);
        $this->convenio = Convenio::create([
            'numero' => '20104415012022', 'name' => 'Gestores Información Gipuzkoa',
            'territory_id' => $this->territory->id, 'sector_id' => $sector->id,
        ]);
        $this->category = ConvenioJobCategory::create([
            'convenio_id' => $this->convenio->id, 'name' => 'Grupo I. Jefes de Área', 'group_code' => 'I',
        ]);
        $type = DocumentType::create(['code' => 'salary_tables', 'name' => 'Tablas salariales']);
        $this->salaryDoc = Document::create([
            'title' => 'Gestores Información Gipuzkoa — Tablas 2026',
            'source_filename' => 'gestores_tablas_2026.xlsx',
            'storage_path' => 'documents/fake/salary.xlsx',
            'content_hash' => hash('sha256', 'salary-'.uniqid()),
            'convenio_id' => $this->convenio->id,
            'document_type_id' => $type->id,
            'retrieval_status' => 'active',
            'authority_level' => 'official_convenio',
            'language' => 'es',
            'tagging_status' => 'verified',
            'ingested_at' => now(),
        ]);
        $this->table = SalaryTable::create([
            'convenio_id' => $this->convenio->id, 'year' => (int) now()->year,
            'source_document_id' => $this->salaryDoc->id,
        ]);
    }

    // ---- 1. the answer states only stored figures ---------------------------

    /**
     * The convenio-15 case itself: the gazette states an annual but no monthly.
     * The answer gives the annual, says nothing about a monthly, and above all
     * never prints 33.491,36 / 14 = 2.392,24 €.
     */
    public function test_a_row_with_no_stored_monthly_states_the_annual_and_omits_the_monthly(): void
    {
        $this->row(['gross_annual' => 33491.36, 'base_salary_monthly' => null, 'pagas_count' => null]);

        $answer = $this->answer();

        $this->assertStringContainsString('bruto anual de 33.491,36 €', $answer);
        $this->assertStringNotContainsString('mensual', $answer);
        $this->assertStringNotContainsString('2.392,24', $answer, 'the derived figure must never appear');
        $this->assertStringNotContainsString('pagas', $answer, 'no pagas count was stated by the source');
    }

    /** A stated pagas count is reported alongside the annual — as a fact, not a divisor. */
    public function test_a_stated_pagas_count_is_reported_but_never_applied(): void
    {
        $this->row(['gross_annual' => 31234.54, 'base_salary_monthly' => null, 'pagas_count' => 14]);

        $answer = $this->answer();

        $this->assertStringContainsString('bruto anual de 31.234,54 € (tabla expresada en 14 pagas)', $answer);
        $this->assertStringNotContainsString('mensual', $answer);
        $this->assertStringNotContainsString('2.231,04', $answer, '31234.54/14 is exactly what must NOT be computed');
    }

    /** When the source DOES state a monthly, it is quoted, with its pagas count. */
    public function test_a_stored_monthly_is_quoted_with_its_stated_pagas_count(): void
    {
        $this->row(['gross_annual' => 33491.36, 'base_salary_monthly' => 2232.75, 'pagas_count' => 15]);

        $answer = $this->answer();

        $this->assertStringContainsString('bruto anual de 33.491,36 €', $answer);
        $this->assertStringContainsString('salario base mensual de 2.232,75 € en 15 pagas', $answer);
        $this->assertStringNotContainsString('tabla expresada', $answer, 'the pagas count rides with the monthly when there is one');
    }

    /** A stored monthly with no stated pagas count is quoted bare — nothing assumed. */
    public function test_a_stored_monthly_without_a_pagas_count_is_quoted_bare(): void
    {
        $this->row(['gross_annual' => 37131.5, 'base_salary_monthly' => 2652.25, 'pagas_count' => null]);

        $answer = $this->answer();

        $this->assertStringContainsString('salario base mensual de 2.652,25 €.', $answer);
        $this->assertStringNotContainsString('pagas', $answer);
    }

    // ---- 2. salary:import fails loudly --------------------------------------

    public function test_import_fails_loudly_when_a_recognized_grid_yields_no_rows(): void
    {
        $this->fakeExtraction([
            'tables' => [],
            'warnings' => ["sheet '2026': a salary-grid header was found on row 0 but NOT ONE data row carried a numeric figure"],
            'sheet_diagnostics' => [['sheet' => '2026', 'status' => 'header_but_no_rows', 'typed_fields' => ['gross_annual'], 'rows' => 0]],
        ]);

        $exit = Artisan::call('salary:import', ['--document' => $this->salaryDoc->uuid]);
        $output = Artisan::output();

        $this->assertSame(1, $exit, 'a recognized-but-empty grid must exit non-zero');
        $this->assertStringNotContainsString('Salary import complete', $output, 'and must never print a success line');
        $this->assertStringContainsString('header_but_no_rows', $output);
        $this->assertStringContainsString('NOTHING written for this document', $output);
        $this->assertSame(0, SalaryTableRow::whereIn('salary_table_id', SalaryTable::where('source_document_id', $this->salaryDoc->id)->pluck('id'))->count());
    }

    public function test_import_fails_loudly_when_a_header_maps_to_nothing(): void
    {
        $this->fakeExtraction([
            'tables' => [],
            'warnings' => [],
            'sheet_diagnostics' => [['sheet' => '2026', 'status' => 'header_maps_to_nothing', 'typed_fields' => [], 'rows' => 0]],
        ]);

        $exit = Artisan::call('salary:import', ['--document' => $this->salaryDoc->uuid]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringNotContainsString('Salary import complete', $output);
        $this->assertStringContainsString('header_maps_to_nothing', $output);
    }

    public function test_import_fails_loudly_when_no_table_is_parsed_at_all(): void
    {
        $this->fakeExtraction(['tables' => [], 'warnings' => [], 'sheet_diagnostics' => [['sheet' => 'Notas', 'status' => 'no_header', 'typed_fields' => [], 'rows' => 0]]]);

        $exit = Artisan::call('salary:import', ['--document' => $this->salaryDoc->uuid]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringNotContainsString('Salary import complete', $output);
        $this->assertStringContainsString('no salary tables parsed', $output);
    }

    /** A notes sheet alongside a real grid stays benign — it must not fail the import. */
    public function test_import_succeeds_with_a_notes_sheet_and_stores_the_sourced_figures(): void
    {
        $this->fakeExtraction([
            'tables' => [[
                'year' => (int) now()->year, 'validity_start' => null, 'validity_end' => null,
                'rows' => [[
                    'job_category_name' => 'Grupo I. Jefes de Área', 'group_code' => 'I',
                    'gross_annual' => 33491.36, 'base_salary_monthly' => 2232.75, 'extra_pay' => null,
                    'pagas_count' => null, 'hourly_rate' => null, 'night_plus' => null,
                    'raw_values' => ['salario base' => '2.232,75', 'salario anual' => '33.491,36'],
                ]],
            ]],
            'warnings' => [],
            'sheet_diagnostics' => [
                ['sheet' => '2026', 'status' => 'ok', 'typed_fields' => ['base_salary_monthly', 'gross_annual'], 'rows' => 1],
                ['sheet' => 'Notes', 'status' => 'no_header', 'typed_fields' => [], 'rows' => 0],
            ],
        ]);

        $exit = Artisan::call('salary:import', ['--document' => $this->salaryDoc->uuid]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Salary import complete', $output);

        $row = SalaryTableRow::whereIn('salary_table_id', SalaryTable::where('source_document_id', $this->salaryDoc->id)->pluck('id'))->firstOrFail();
        $this->assertEqualsWithDelta(2232.75, $row->base_salary_monthly, 0.001, 'the monthly is the source cell, not gross/14');
        $this->assertNull($row->pagas_count, 'the source states no pagas count, so none is stored');
    }

    // ---- 3. the audit guard -------------------------------------------------

    /** The pre-correction state: a stored monthly that contradicts the source's own. */
    public function test_the_audit_reports_a_stored_monthly_that_contradicts_the_source(): void
    {
        $this->row([
            'gross_annual' => 33491.36,
            'base_salary_monthly' => 2392.24,   // 33491.36 / 14, the old derivation
            'pagas_count' => 14,                // asserted by nothing in the source
            'raw_values' => ['salario base' => '2.232,75', 'salario anual' => '33.491,36'],
        ]);

        $exit = Artisan::call('salary:audit-monthly');
        $output = Artisan::output();

        $this->assertSame(1, $exit, 'any discrepancy must exit non-zero');
        $this->assertStringContainsString('DISCREPANCY', $output);
        $this->assertStringContainsString('2.392,24', $output, 'the stored figure');
        $this->assertStringContainsString('2.232,75', $output, 'what the source actually states');
        $this->assertStringContainsString('UNSOURCED PAGAS COUNT', $output, 'no header states 14 pagas');
        $this->assertStringContainsString('Grupo I. Jefes de Área', $output);
    }

    /** A monthly with no counterpart anywhere in raw_values is, by definition, derived. */
    public function test_the_audit_reports_an_unsourced_monthly(): void
    {
        $this->row([
            'gross_annual' => 24748.65,
            'base_salary_monthly' => 1767.76,
            'raw_values' => ['total anual' => '24.748,65', 'precio/hora' => '14,79'],
        ]);

        $exit = Artisan::call('salary:audit-monthly');

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('UNSOURCED MONTHLY', Artisan::output());
    }

    /** The post-correction state: every stored figure has a source cell behind it. */
    public function test_the_audit_passes_when_every_stored_figure_is_sourced(): void
    {
        $this->row([
            'gross_annual' => 33491.36,
            'base_salary_monthly' => 2232.75,
            'pagas_count' => null,
            'raw_values' => ['salario base' => '2.232,75', 'salario anual' => '33.491,36'],
        ]);

        $exit = Artisan::call('salary:audit-monthly');

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('No discrepancies', Artisan::output());
    }

    /**
     * A monthly the source prints and no typed column holds is a COVERAGE note,
     * not a correctness failure — sometimes a gap worth closing, sometimes the
     * parser rightly refusing an ambiguous figure (a multi-year sheet printing
     * two "14 pagas" columns settles no year for either). Nothing wrong is
     * stored, so the command must report it and still exit 0.
     */
    public function test_a_stated_monthly_that_is_not_stored_is_reported_but_does_not_fail(): void
    {
        $this->row([
            'gross_annual' => null,
            'base_salary_monthly' => null,
            'raw_values' => ['14 pagas' => '2.166,06', '14 pagas (2)' => '2.231,04'],
        ]);

        $exit = Artisan::call('salary:audit-monthly');
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('no typed column holds', $output);
        $this->assertStringContainsString('Not a failure', $output);
    }

    /**
     * A sheet that prints "14 pagas" and "12 pagas" side by side states two
     * monthlies; storing either one is sourced, and the audit must not read the
     * mismatch with the other as a discrepancy.
     */
    public function test_the_audit_accepts_whichever_stated_monthly_was_stored(): void
    {
        $this->row([
            'base_salary_monthly' => 2231.04,
            'pagas_count' => 14,
            'raw_values' => ['14 pagas' => '2.231,04', '12 pagas' => '2.602,88'],
        ]);

        $exit = Artisan::call('salary:audit-monthly');

        $this->assertSame(0, $exit, 'the stored figure matches the 14-pagas column the header names');
    }

    // ---- 4. every monthly figure is named by its own source column ----------

    /**
     * The convenio-10 shape: the sheet prints TWO monthly figures that are not the
     * same quantity — `salario base` 1.183,34 and `bruto mes` 1.771,64 (the base
     * plus its prorated extras). Both are stated, each under its own header, and
     * neither is called a bare "salario mensual", which would name a quantity the
     * employee cannot check against a payslip.
     */
    public function test_two_monthly_columns_are_both_stated_each_named_by_its_own_header(): void
    {
        $this->row([
            'gross_annual' => 21259.75,
            'base_salary_monthly' => 1183.34,
            'base_salary_monthly_label' => 'salario base',
            'raw_values' => [
                'bruto mes' => 1771.64,
                'bruto anual' => 21259.752,
                'salario base' => 1183.34,
                'p.p.paga extra' => 394.446667,
                'plus transporte' => 114.97,
            ],
        ]);

        $answer = $this->answer();

        $this->assertStringContainsString('salario base mensual de 1.183,34 €', $answer);
        $this->assertStringContainsString('bruto mensual de 1.771,64 €', $answer);
        $this->assertStringNotContainsString('salario mensual de', $answer, 'the generic label names neither quantity');
        $this->assertStringNotContainsString('394,45', $answer, 'a prorated extra is not a monthly salary');
        $this->assertStringNotContainsString('114,97', $answer, 'nor is a transport plus');
    }

    /**
     * COEAS Navarra reads its monthly from a column headed "14 pagas". Calling
     * that "salario base mensual" would attach a name the source never used, so
     * the figure is named after the column — and the 12-pagas figure printed
     * beside it is stated too, since the source prints both.
     */
    public function test_a_monthly_read_from_a_pagas_column_is_named_after_that_column(): void
    {
        $this->row([
            'base_salary_monthly' => 2231.04,
            'base_salary_monthly_label' => '14 pagas',
            'pagas_count' => 14,
            'raw_values' => ['14 pagas' => 2231.038668, '12 pagas' => 2602.878446, 'hora' => 18.33013],
        ]);

        $answer = $this->answer();

        $this->assertStringContainsString('importe mensual en 14 pagas de 2.231,04 €', $answer);
        $this->assertStringContainsString('importe mensual en 12 pagas de 2.602,88 €', $answer);
        $this->assertStringNotContainsString('salario base', $answer, 'the source never calls this column a base salary');
        $this->assertStringNotContainsString('en 14 pagas en 14 pagas', $answer, 'the count is not repeated when the column name states it');
    }

    /** An unrecognized header is QUOTED, never paraphrased into a meaning it may not have. */
    public function test_an_unrecognized_monthly_header_is_quoted_verbatim(): void
    {
        $this->row([
            'base_salary_monthly' => 1500.00,
            'base_salary_monthly_label' => 'Retribución de tabla (mes)',
            'raw_values' => [],
        ]);

        $answer = $this->answer();

        $this->assertStringContainsString('«Retribución de tabla (mes)» de 1.500,00 €', $answer);
    }

    /**
     * `--mark-provenance` adds the original OCR'd header as a SECOND key holding
     * the same value. That is one printed figure under two names, and it must be
     * stated once.
     */
    public function test_the_same_figure_restored_under_a_verbatim_key_is_stated_once(): void
    {
        $this->row([
            'base_salary_monthly' => 2232.75,
            'base_salary_monthly_label' => "Salario base (mes)\n(€)",
            'raw_values' => ['salario base' => 2232.75, "Salario base (mes)\n(€)" => 2232.75],
        ]);

        $answer = $this->answer();

        $this->assertSame(1, substr_count($answer, '2.232,75'), 'one source figure, one mention');
        $this->assertStringContainsString('salario base mensual de 2.232,75 €', $answer);
    }

    /** The second year of a multi-year block belongs to another table's year — never quoted here. */
    public function test_a_suffixed_duplicate_key_is_never_quoted(): void
    {
        $this->row([
            'base_salary_monthly' => 2166.06,
            'base_salary_monthly_label' => '14 pagas',
            'raw_values' => ['14 pagas' => 2166.06, '14 pagas (2)' => 2231.04],
        ]);

        $answer = $this->answer();

        $this->assertStringContainsString('2.166,06', $answer);
        $this->assertStringNotContainsString('2.231,04', $answer, "the next year's figure is not this table's");
    }

    /**
     * With no typed monthly the sheet's monthly is unsettled (ADR-0027's
     * multi-year block) — the raw cells are NOT offered as a substitute, or the
     * answer would hand over four figures and no way to choose between them.
     */
    public function test_raw_monthly_cells_are_not_quoted_when_no_monthly_is_typed(): void
    {
        $this->row([
            'gross_annual' => null,
            'base_salary_monthly' => null,
            'raw_values' => ['14 pagas' => 1365.16, '12 pagas' => 1592.69, '14 pagas (2)' => 1406.12],
        ]);

        $answer = $this->answer();

        $this->assertStringNotContainsString('mensual', $answer);
        $this->assertStringNotContainsString('1.365,16', $answer);
    }

    // ---- helpers ------------------------------------------------------------

    private function row(array $attributes): SalaryTableRow
    {
        return SalaryTableRow::create(array_merge([
            'salary_table_id' => $this->table->id,
            'job_category_id' => $this->category->id,
            'raw_values' => [],
        ], $attributes));
    }

    private function answer(): string
    {
        $employee = Employee::create([
            'email' => 'emp15@example.com', 'full_name' => 'Empleada Gipuzkoa',
            'convenio_id' => $this->convenio->id, 'job_category_id' => $this->category->id,
            'territory_id' => $this->territory->id, 'employment_type' => 'full_time', 'status' => 'active',
        ]);

        $result = app(SalaryAnswerService::class)->answer($employee, Carbon::create((int) now()->year, 6, 1));

        $this->assertSame(SalaryAnswerService::OUTCOME_ANSWER, $result['outcome']);

        return $result['answer'];
    }

    /** @param  array<string,mixed>  $response */
    private function fakeExtraction(array $response): void
    {
        $this->app->instance(ExtractionClient::class, new class($response) extends ExtractionClient
        {
            public function __construct(private array $response) {}

            public function extractSalary(string $storageKey, string $documentUuid): array
            {
                return $this->response;
            }
        });
    }
}
