<?php

namespace Tests\Feature;

use App\Models\AnswerModelSetting;
use App\Models\Convenio;
use App\Models\ConvenioJobCategory;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Employee;
use App\Models\SalaryTable;
use App\Models\SalaryTableRow;
use App\Models\Sector;
use App\Models\Territory;
use App\Services\ChatService;
use App\Services\RouterService;
use App\Support\EscalationExplainer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Sprint 10b, Correction-01 (post-D1-bis regression, found on Pedram's own
 * eyes-on pass, merge blocked). D1-bis (this same sprint) added SMI/salario
 * mínimo patterns to `RouterService::SALARY_PATTERNS` so the question routes
 * deterministically to the salary path instead of misrouting off_domain — but
 * its own gold-eval only ever ran the NO-TABLES profile
 * (`test-fullgap@example.com`-shaped), where the coverage-gap escalation
 * looked correct for the wrong reason (there was no table to answer from
 * either way). It never exercised a `test-navarra@example.com`-shaped
 * employee — one WITH a resolvable salary table and category — where
 * `SalaryAnswerService::answer()` doesn't know WHAT was asked, only WHO is
 * asking, and happily answers the employee's own category cell to a question
 * about a national, not-per-category figure.
 *
 * The contract this file pins: an SMI/salario-mínimo question escalates
 * `salary_coverage_gap` (sub_outcome `statutory_figure`) for EVERY employee
 * profile — tables or not — because the check runs in `ChatService` BEFORE
 * `SalaryAnswerService::answer()` is ever called, never after.
 */
class Sprint10bCorrection01Test extends TestCase
{
    use RefreshDatabase;

    private Convenio $convenio;

    private Employee $employeeNoTables;

    private Employee $employeeWithTables;

    protected function setUp(): void
    {
        parent::setUp();

        AnswerModelSetting::query()->delete();
        $s = new AnswerModelSetting(['provider' => 'claude']);
        $s->id = 1;
        $s->setKey('test-key-1234');

        $territory = Territory::create(['code' => '31', 'name' => 'Navarra', 'level' => 'provincial', 'aliases' => []]);
        $sector = Sector::create(['name' => 'Hostelería', 'aliases' => []]);
        $this->convenio = Convenio::create([
            'numero' => '31TESTC01', 'name' => 'Convenio Correction-01',
            'territory_id' => $territory->id, 'sector_id' => $sector->id,
        ]);

        // No-tables profile — mirrors test-fullgap@example.com. D1-bis's own
        // gold-eval already covers this shape; kept here as the control.
        $this->employeeNoTables = Employee::create([
            'email' => 'test-c01-no-tables@example.com', 'full_name' => 'Worker No Tables',
            'convenio_id' => $this->convenio->id, 'territory_id' => $territory->id,
            'employment_type' => 'full_time', 'status' => 'active',
        ]);

        // With-tables profile — mirrors test-navarra@example.com, the shape
        // D1-bis's gold-eval never ran. A resolvable table AND category, so an
        // ORDINARY salary question answers from the SQL row (asserted below,
        // as the no-change control) — this is the exact setup an SMI question
        // must NOT be allowed to reach.
        $category = ConvenioJobCategory::create([
            'convenio_id' => $this->convenio->id, 'name' => 'Camarero/a', 'group_code' => 'II',
        ]);
        $docType = DocumentType::create(['code' => 'salary_tables', 'name' => 'Tablas salariales']);
        $salaryDoc = Document::create([
            'title' => 'Convenio Correction-01 — Tablas', 'source_filename' => 'c01_tablas.xlsx',
            'storage_path' => 'documents/fake/'.uniqid(), 'content_hash' => hash('sha256', 'c01-'.uniqid()),
            'convenio_id' => $this->convenio->id, 'document_type_id' => $docType->id,
            'retrieval_status' => 'active', 'authority_level' => 'official_convenio',
            'language' => 'es', 'tagging_status' => 'verified', 'ingested_at' => now(),
        ]);
        $table = SalaryTable::create([
            'convenio_id' => $this->convenio->id, 'year' => (int) now()->year,
            'source_document_id' => $salaryDoc->id,
        ]);
        SalaryTableRow::create([
            'salary_table_id' => $table->id, 'job_category_id' => $category->id,
            'gross_annual' => 21500.00, 'raw_values' => [],
        ]);
        $this->employeeWithTables = Employee::create([
            'email' => 'test-c01-with-tables@example.com', 'full_name' => 'Worker With Tables',
            'convenio_id' => $this->convenio->id, 'territory_id' => $territory->id,
            'job_category_id' => $category->id,
            'employment_type' => 'full_time', 'status' => 'active',
        ]);
    }

    /** @return array<string,array{0:string}> */
    public static function smiQuestions(): array
    {
        return [
            'acronym' => ['¿Cuál es el SMI este año?'],
            'spelled out' => ['¿ha subido el salario mínimo interprofesional este año?'],
        ];
    }

    #[DataProvider('smiQuestions')]
    public function test_smi_question_escalates_for_the_no_tables_profile(string $question): void
    {
        $result = app(ChatService::class)->handleMessage($this->employeeNoTables, $question);

        $this->assertSame('escalate', $result['outcome']);
        $this->assertSame('salary_coverage_gap', $result['trace']['floor_decision']['escalation_reason']);
        $this->assertSame(
            'statutory_figure',
            EscalationExplainer::explain('salary_coverage_gap', $result['trace'])['sub_outcome']
        );
    }

    /**
     * THE regression test. Before this fix: `test-navarra@example.com`-shaped
     * employees got `OUTCOME_ANSWER` with their own category's 21.500,00 €
     * figure — a real number, non-responsive to the question actually asked.
     */
    #[DataProvider('smiQuestions')]
    public function test_smi_question_escalates_for_the_with_tables_profile_the_d1_bis_regression(string $question): void
    {
        $result = app(ChatService::class)->handleMessage($this->employeeWithTables, $question);

        $this->assertSame(
            'escalate',
            $result['outcome'],
            'regression: an SMI question must never resolve to an ANSWER, even for an employee with a full salary table'
        );
        $this->assertSame('salary_coverage_gap', $result['trace']['floor_decision']['escalation_reason']);
        $this->assertSame(
            'statutory_figure',
            EscalationExplainer::explain('salary_coverage_gap', $result['trace'])['sub_outcome']
        );
        // The employee-facing text is always the one fixed neutral message
        // (ADR-0029) — asserting it here also proves this path went through
        // persistTurn()'s override, not some other code path.
        $this->assertSame(ChatService::EMPLOYEE_ESCALATION_MESSAGE, $result['answer']);
        // Never the employee's own category-cell figure, under any wrapping.
        $this->assertStringNotContainsString('21.500', $result['answer']);
        // SalaryAnswerService::answer() must never have run for this turn — its
        // 'row'/'table_id'/'job_category_id' keys are absent from the trace.
        $this->assertArrayNotHasKey('row', $result['trace']['salary']);
        $this->assertArrayNotHasKey('table_id', $result['trace']['salary']);
    }

    /**
     * The no-change control: an ORDINARY salary question for the SAME
     * with-tables employee must still answer from the SQL row exactly as
     * before — proving the fix is narrow (SMI/salario-mínimo only), not a
     * blanket new escalation on the salary path.
     */
    public function test_an_ordinary_salary_question_still_answers_for_the_with_tables_profile(): void
    {
        $result = app(ChatService::class)->handleMessage($this->employeeWithTables, '¿Cuánto gano?');

        $this->assertSame('answer', $result['outcome']);
        $this->assertStringContainsString('21.500,00 €', $result['answer']);
    }

    public function test_smi_still_routes_deterministically_no_llm_involved(): void
    {
        $router = app(RouterService::class);

        $this->assertTrue($router->matchesSalary('¿Cuál es el SMI este año?'));
        $this->assertTrue($router->matchesStatutorySalaryFigure('¿Cuál es el SMI este año?'));
        $this->assertTrue($router->matchesStatutorySalaryFigure('¿ha subido el salario mínimo interprofesional este año?'));

        // The narrowness guarantee: an ordinary salary question matches
        // SALARY_PATTERNS but NOT the statutory subset.
        $this->assertTrue($router->matchesSalary('¿Cuánto gano?'));
        $this->assertFalse($router->matchesStatutorySalaryFigure('¿Cuánto gano?'));
    }

    public function test_the_new_sub_outcome_is_covered_by_the_7g_matrix_and_registry(): void
    {
        $this->assertContains('salary_coverage_gap.statutory_figure', EscalationExplainer::MATRIX);
        $this->assertTrue(EscalationExplainer::registryHasEntry('salary_coverage_gap.statutory_figure'));

        $explanation = EscalationExplainer::explain('salary_coverage_gap', ['salary' => ['note' => 'statutory figure (SMI/salario mínimo) — never sourced from the employee\'s own convenio salary table, regardless of whether one exists (Correction-01)']]);
        $this->assertNotEmpty($explanation['fix_action']);
        $this->assertNotEmpty($explanation['asked']);
    }
}
