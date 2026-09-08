<?php

namespace Tests\Feature;

use App\Models\AnswerModelSetting;
use App\Models\Convenio;
use App\Models\Document;
use App\Models\DocumentPage;
use App\Models\DocumentType;
use App\Models\Sector;
use App\Models\SalaryTable;
use App\Models\SalaryTableRow;
use App\Models\Territory;
use App\Services\ExtractionClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Sprint 7e follow-up (Option A, review.md §5) — the salary-PDF-via-OCR round
 * trip: fake `table_rows` (sidecar) → derived `.xlsx` → the REAL, unmodified
 * `salary:import` → `salary_tables`/`salary_table_rows` → `--mark-provenance`.
 *
 * Proves:
 *  - the derived `.xlsx` is written verbatim (no cell normalization) and a
 *    NEW `documents` row (`derived_from_document_id`-linked) is created so
 *    `salary:import`'s OWN, UNCHANGED query picks it up;
 *  - an out-of-range cell (an hourly_rate far past hr-ai's decimal(8,4)
 *    bound, salary.py's `_FIELD_BOUNDS`) is DROPPED from the typed column but
 *    KEPT in `raw_values`, with a warning — never imported, never guessed;
 *  - `--mark-provenance` stamps `salary_tables.source = 'ocr_pdf'` only after
 *    a real `salary:import` run, and errors if run before one.
 */
class Sprint7eSalaryPdfToXlsxTest extends TestCase
{
    use RefreshDatabase;

    private Convenio $convenio;

    private DocumentType $salaryType;

    private FakeSalaryOcrExtractionClient $fake;

    protected function setUp(): void
    {
        parent::setUp();

        $territory = Territory::create(['code' => '20', 'name' => 'Gipuzkoa', 'level' => 'provincial', 'aliases' => []]);
        $sector = Sector::create(['name' => 'Información y Documentación', 'aliases' => []]);
        $this->convenio = Convenio::create([
            'numero' => '20104415012022', 'name' => 'Gestores Información Gipuzkoa',
            'territory_id' => $territory->id, 'sector_id' => $sector->id,
        ]);
        $this->salaryType = DocumentType::create(['code' => 'salary_tables', 'name' => 'Tablas salariales']);

        $this->fake = new FakeSalaryOcrExtractionClient;
        $this->app->instance(ExtractionClient::class, $this->fake);

        $setting = new AnswerModelSetting(['provider' => 'claude']);
        $setting->id = 1;
        $setting->save();
        $setting->setKey('sk-test-key-salary', null);
    }

    private function makeSalaryPdfDocument(): Document
    {
        $document = Document::create([
            'title' => 'Gestores Información Gipuzkoa — Tablas 2025',
            'source_filename' => '20104415012022_Gestores_Tablas_2025.pdf',
            'storage_path' => 'documents/fake-uuid/original.pdf',
            'content_hash' => hash('sha256', 'salary-pdf-'.uniqid()),
            'convenio_id' => $this->convenio->id,
            'document_type_id' => $this->salaryType->id,
            'retrieval_status' => 'active',
            'authority_level' => 'official_convenio',
            'language' => 'es',
            'tagging_status' => 'auto_proposed',
            'ingested_at' => now(),
        ]);
        DocumentPage::create([
            'document_id' => $document->id,
            'page_number' => 1,
            'text' => '',
            'image_path' => "documents/{$document->uuid}/pages/0001.jpg",
            'extraction_source' => 'text_layer', // pre-7e ingest, never OCR'd
        ]);

        return $document;
    }

    public function test_round_trip_writes_verbatim_xlsx_and_creates_derived_document(): void
    {
        Storage::fake('s3');
        $document = $this->makeSalaryPdfDocument();

        // The pinned table contract's sidecar shape (article_headers = title
        // ONLY, columns = footnote/plus-line ONLY, table_rows = the grid ONLY).
        // One cell ("99999,9999") is deliberately far past hourly_rate's
        // decimal(8,4)/9999.9999 bound (salary.py) — this is the out-of-range
        // proof cell.
        $this->fake->ocrPageResponse = [
            'text' => 'ANEXO I: TABLA SALARIAL', 'layout' => 'table', 'bilingual' => false,
            'quality' => 0.93, 'cost_usd' => 0.041, 'sec_per_page' => 22.1, 'engine' => 'claude-opus-5',
        ];

        Artisan::call('salary:pdf-to-xlsx', ['--document' => $document->uuid]);
        $output = Artisan::output();

        $page = DocumentPage::where('document_id', $document->id)->first();
        $this->assertSame('ocr', $page->extraction_source);

        // The sidecar itself is written by hr-ai in production; this test
        // exercises hr-backend's OWN read-back + xlsx-write path, so it seeds
        // the sidecar directly at the exact key `ocr.py`'s `ocr_sidecar_key()`
        // computes — the same contract `OcrService`/`hr-ai` already prove
        // elsewhere (Sprint7eOcrInvariantTest, ocr_sidecar_test.py).
        //
        // NOTE: because `ocrOnePage()` (real, unfaked) already ran above via
        // the faked hr-ai HTTP call, and hr-ai (not exercised here) is what
        // writes the sidecar, the command's own read of a MISSING sidecar
        // reports it as skipped. To prove the xlsx-writing logic itself, seed
        // the sidecar now and re-run the command (idempotent — page already
        // `extraction_source = 'ocr'`, so no second OCR call is made).
        $sidecarKey = "documents/{$document->uuid}/ocr/0001.json";
        Storage::disk('s3')->put($sidecarKey, json_encode([
            'layout' => 'table',
            'columns' => [
                ['order' => 0, 'language' => 'es', 'text' => 'Plus Festivo: 3,18 €/h.'],
            ],
            'table_rows' => [
                ['Categoría Profesional', 'Salario Base (12 pagas)', 'Precio hora', 'Plus nocturno'],
                ['Grupo 1', '1.968,28', '17,33', '3,45'],
                ['Grupo 2', '1.795,58', '99999,9999', '3,15'], // out-of-range hourly_rate
            ],
            'article_headers' => ['ANEXO I: TABLA SALARIAL DE 1-1-2025 A 31-12-2025'],
        ]));

        Artisan::call('salary:pdf-to-xlsx', ['--document' => $document->uuid]);
        $output = Artisan::output();
        $this->assertStringContainsString('already OCR', $output); // no re-OCR — the reused-sidecar path
        $this->assertStringContainsString('Derived .xlsx written', $output);
        $this->assertStringContainsString('Categoría Profesional', $output); // header preview in the review table

        $derived = Document::where('derived_from_document_id', $document->id)->first();
        $this->assertNotNull($derived, 'a derived xlsx document row must be created');
        $this->assertSame('documents/'.$document->uuid.'/derived/salary.xlsx', $derived->storage_path);
        $this->assertSame($this->convenio->id, $derived->convenio_id);
        $this->assertSame($this->salaryType->id, $derived->document_type_id);
        $this->assertTrue(Storage::disk('s3')->exists($derived->storage_path));
        $this->assertFileExists(storage_path("app/salary-derived/{$document->uuid}/salary.xlsx"));

        // Re-running is idempotent: reuses the SAME derived document row.
        Artisan::call('salary:pdf-to-xlsx', ['--document' => $document->uuid]);
        $this->assertSame(1, Document::where('derived_from_document_id', $document->id)->count());

        // ---- step 3: the REAL, unmodified salary:import, reading the actual
        // bytes this command wrote (via the faked extractSalary(), which for
        // this test parses the real xlsx bytes with the SAME rules as hr-ai's
        // salary.py, proving the round trip end to end).
        $this->fake->xlsxBytesToParse = Storage::disk('s3')->get($derived->storage_path);
        Artisan::call('salary:import', ['--document' => $derived->uuid]);
        $importOutput = Artisan::output();

        $table = SalaryTable::where('source_document_id', $derived->id)->first();
        $this->assertNotNull($table, 'salary:import must have written a salary_tables row for the derived document');
        $this->assertSame('xlsx_native', $table->source, 'salary:import itself never sets source — it keeps the column default');

        $rows = SalaryTableRow::where('salary_table_id', $table->id)->get();
        $this->assertCount(2, $rows);

        $grupo2 = $rows->first(fn ($r) => $r->jobCategory->name === 'Grupo 2');
        $this->assertNotNull($grupo2);
        // The out-of-range cell: dropped from the typed column...
        $this->assertNull($grupo2->hourly_rate, 'an out-of-range hourly_rate must be dropped, never force-fit');
        // ...but kept verbatim in raw_values...
        $this->assertEquals('99999,9999', $grupo2->raw_values['precio hora'] ?? $grupo2->raw_values['Precio hora'] ?? collect($grupo2->raw_values)->first());
        // ...and warned about (never silently dropped).
        $this->assertStringContainsString('out of range', $importOutput);

        $grupo1 = $rows->first(fn ($r) => $r->jobCategory->name === 'Grupo 1');
        $this->assertEqualsWithDelta(17.33, $grupo1->hourly_rate, 0.001, 'an in-range hourly_rate must be imported normally');

        // ---- step 4: --mark-provenance -----------------------------------
        Artisan::call('salary:pdf-to-xlsx', ['--document' => $document->uuid, '--mark-provenance' => true]);
        $markOutput = Artisan::output();
        $this->assertStringContainsString('Marked 1 salary_tables row', $markOutput);

        $table->refresh();
        $this->assertSame('ocr_pdf', $table->source);
    }

    public function test_mark_provenance_before_import_errors_not_silently_noops(): void
    {
        Storage::fake('s3');
        $document = $this->makeSalaryPdfDocument();

        $exit = Artisan::call('salary:pdf-to-xlsx', ['--document' => $document->uuid, '--mark-provenance' => true]);
        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('run `salary:pdf-to-xlsx', Artisan::output());
    }

    public function test_native_xlsx_document_is_rejected(): void
    {
        Storage::fake('s3');
        $document = Document::create([
            'title' => 'Already xlsx', 'source_filename' => 'x.xlsx', 'storage_path' => 'documents/x/original.xlsx',
            'content_hash' => hash('sha256', 'x'.uniqid()), 'convenio_id' => $this->convenio->id,
            'document_type_id' => $this->salaryType->id, 'retrieval_status' => 'active',
            'authority_level' => 'official_convenio', 'language' => 'es', 'tagging_status' => 'auto_proposed',
            'ingested_at' => now(),
        ]);

        Artisan::call('salary:pdf-to-xlsx', ['--document' => $document->uuid]);
        $this->assertStringContainsString('already a native .xlsx', Artisan::output());
        $this->assertNull(Document::where('derived_from_document_id', $document->id)->first());
    }
}

/**
 * Fakes exactly `ocrPage()` (the vision call) and `extractSalary()` (routed
 * to a REAL `openpyxl`-equivalent parse in PHP would duplicate hr-ai's
 * `salary.py` — instead this fake defers to a tiny PHP re-implementation of
 * ITS bounds-check rule for the one property this test proves: an
 * out-of-range typed value is dropped to raw_values + warned, never
 * imported). Every other method is an unused-here stub.
 */
class FakeSalaryOcrExtractionClient extends ExtractionClient
{
    /** @var array<string,mixed> */
    public array $ocrPageResponse = [];

    public ?string $xlsxBytesToParse = null;

    public function ocrPage(string $documentUuid, int $pageNumber, string $imageKey, string $decryptedKey, array $providerConfig): array
    {
        return $this->ocrPageResponse;
    }

    /**
     * A faithful-enough re-implementation of hr-ai's `salary.py` bounds/
     * mapping rule for THIS test's fixture shape only (header-synonym match
     * + decimal(8,4) hourly_rate bound) — proving the contract hr-backend
     * depends on, not re-testing hr-ai's own parser (that is
     * `hr-ai/scripts/ocr_sidecar_test.py`'s and `salary.py`'s job).
     */
    public function extractSalary(string $storageKey, string $documentUuid): array
    {
        $bytes = $this->xlsxBytesToParse;
        if ($bytes === null) {
            return ['tables' => [], 'warnings' => ['no xlsx bytes provided to the fake']];
        }

        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReader('Xlsx');
        $spreadsheet = $reader->load($this->writeTemp($bytes));
        $tables = [];
        $warnings = [];
        foreach ($spreadsheet->getSheetNames() as $name) {
            if ($name === 'Notes') {
                continue;
            }
            $sheet = $spreadsheet->getSheetByName($name);
            $grid = $sheet->toArray(null, true, true, false);
            $header = array_map(fn ($h) => mb_strtolower(trim((string) $h)), $grid[0] ?? []);
            $rows = array_slice($grid, 1);
            $hourlyIdx = array_search('precio hora', $header, true);
            $out = [];
            foreach ($rows as $row) {
                if (($row[0] ?? '') === '') {
                    continue;
                }
                $raw = [];
                foreach ($header as $i => $h) {
                    if (isset($row[$i]) && $row[$i] !== '') {
                        $raw[$h] = $row[$i];
                    }
                }
                $hourly = null;
                if ($hourlyIdx !== false && isset($row[$hourlyIdx]) && $row[$hourlyIdx] !== '') {
                    $val = (float) str_replace(',', '.', (string) $row[$hourlyIdx]);
                    if (abs($val) < 9999.9999) {
                        $hourly = round($val, 4);
                    } else {
                        $warnings[] = "sheet '{$name}': hourly_rate={$row[$hourlyIdx]} out of range for '{$row[0]}' — kept in raw_values only, not written as hourly_rate";
                    }
                }
                $out[] = [
                    'job_category_name' => (string) $row[0],
                    'group_code' => null,
                    'gross_annual' => null,
                    'base_salary_monthly' => null,
                    'extra_pay' => null,
                    'num_payments' => null,
                    'hourly_rate' => $hourly,
                    'night_plus' => null,
                    'raw_values' => $raw,
                ];
            }
            if ($out !== []) {
                $tables[] = ['year' => 2025, 'validity_start' => null, 'validity_end' => null, 'rows' => $out];
            }
        }

        return ['tables' => $tables, 'warnings' => $warnings];
    }

    private function writeTemp(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'salaryxlsx').'.xlsx';
        file_put_contents($path, $bytes);

        return $path;
    }
}
