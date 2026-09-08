<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Models\DocumentPage;
use App\Models\SalaryTable;
use App\Services\OcrService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Sprint 7e follow-up — salary-PDF import via the EXISTING `.xlsx` path
 * (Option A, review.md §5). NO change to `salary:import`/hr-ai's
 * `parse_salary_xlsx()` (`app/salary.py`): both stay exactly as they are.
 *
 * The gap this closes: a salary_tables document whose ONLY source is a
 * scanned/PDF grid (`SALARY_PDF_NOT_IMPORTED` in corpus-coverage.md) is
 * invisible to `salary:import`'s query (`storage_path like '%.xlsx'` —
 * hr-ai's `/extract-salary` only ever reads an actual `.xlsx` workbook, never
 * a PDF). This command:
 *
 *  1. OCRs every page of the PDF that isn't already OCR'd (reusing
 *     `OcrService::ocrOnePage()` — same call, same pinned table contract,
 *     same S3 sidecar `documents/{uuid}/ocr/{page:04d}.json` as the prose
 *     fallback; ADR-0026 §2.2/§2.3). Table pages under the pinned contract
 *     (review.md §1.6) put the title ONLY in `article_headers`, a footnote/
 *     plus-line block ONLY in one `columns` `es` entry, and ONLY the grid in
 *     `table_rows` — so reading `table_rows` back out never picks up a title
 *     or a footnote as a spurious row/column.
 *  2. Writes each page's `table_rows` VERBATIM (no normalization, no type
 *     coercion — every cell is an explicit STRING, so "1.968,28" round-trips
 *     exactly as OCR read it) to one sheet per page of a NEW `.xlsx`, at
 *     `documents/{uuid}/derived/salary.xlsx` (S3) + a local path. Titles and
 *     footnote/plus-line text go to a `Notes` sheet, never into the grid.
 *  3. Prints a per-page review table (row/col counts, header preview, a
 *     10-minute pre-signed link to that page's source image) for a HUMAN to
 *     check the `.xlsx` against the original PDF before anything is
 *     imported.
 *  4. Creates (or reuses) ONE `documents` row for the derived `.xlsx`
 *     artifact — same convenio/document_type/validity as the original PDF,
 *     linked back via `derived_from_document_id` — so the EXISTING,
 *     UNMODIFIED `salary:import --document=<derived-uuid>` picks it up via
 *     its own `storage_path like '%.xlsx'` query, no code change required.
 *
 * Step 2 of the workflow (human review) and step 3 (running `salary:import`
 * on approval) happen OUTSIDE this command, exactly as designed — this
 * command never calls `salary:import` itself and never writes a
 * `salary_tables`/`salary_table_rows` row. `--mark-provenance` is the FOURTH,
 * separate, human-gated step: run only AFTER a reviewed `salary:import` has
 * already written rows for the derived document — it stamps
 * `salary_tables.source = 'ocr_pdf'` on exactly those rows.
 */
class SalaryPdfToXlsx extends Command
{
    protected $signature = 'salary:pdf-to-xlsx
        {--document= : the salary_tables PDF document uuid}
        {--mark-provenance : after a human-approved salary:import run against the derived xlsx, stamp its salary_tables row(s) source=ocr_pdf}';

    protected $description = 'OCR a salary_tables PDF\'s table pages and write a derived .xlsx for the existing salary:import path (Sprint 7e follow-up, Option A). Never imports, never writes salary_tables rows itself.';

    public function handle(OcrService $ocr): int
    {
        $uuid = $this->option('document');
        if (! $uuid) {
            $this->error('--document=<uuid> is required.');

            return self::FAILURE;
        }

        $document = Document::with(['documentType', 'pages' => fn ($q) => $q->orderBy('page_number')])
            ->where('uuid', $uuid)->first();
        if ($document === null) {
            $this->error("No document found for uuid {$uuid}.");

            return self::FAILURE;
        }
        if ($document->documentType?->code !== 'salary_tables') {
            $this->error("Document {$uuid} is document_type '{$document->documentType?->code}', not 'salary_tables'.");

            return self::FAILURE;
        }

        if ($this->option('mark-provenance')) {
            return $this->markProvenance($document);
        }

        $ext = strtolower(pathinfo((string) $document->storage_path, PATHINFO_EXTENSION));
        if ($ext === 'xlsx') {
            $this->warn("Document {$uuid} is already a native .xlsx — run `salary:import --document={$uuid}` directly; this command is only for PDF-sourced salary grids.");

            return self::SUCCESS;
        }
        if ($ext !== 'pdf') {
            $this->error("Document {$uuid} has an unsupported storage extension '.{$ext}' (expected .pdf).");

            return self::FAILURE;
        }

        $pages = $document->pages;
        if ($pages->isEmpty()) {
            $this->error("Document {$uuid} has no pages.");

            return self::FAILURE;
        }

        $this->info("Salary PDF: [{$document->id}] {$document->source_filename} — {$pages->count()} page(s).");

        // ---- step 1: OCR every page that isn't already OCR'd -----------------
        $pageStatus = [];
        foreach ($pages as $page) {
            if ($page->extraction_source === 'ocr') {
                $this->line("  page {$page->page_number}: already OCR'd (quality={$page->ocr_quality}) — reusing its sidecar, not re-calling the model.");
                $pageStatus[$page->page_number] = ['ocr_status' => 'already_ocrd', 'quality' => $page->ocr_quality];

                continue;
            }
            if (empty($page->image_path)) {
                $this->warn("  page {$page->page_number}: no image_path — cannot OCR, skipped.");
                $pageStatus[$page->page_number] = ['ocr_status' => 'no_image'];

                continue;
            }

            // Force through the OCR gate regardless of any existing (reading-
            // order, non-grid) native text — `documents:ocr-backfill`'s
            // "all pages text-less" selector does not apply here: what this
            // command needs is the structured `table_rows` sidecar, which is
            // written ONLY by the OCR path, never by native /extract.
            $page->update(['extraction_source' => 'ocr_pending']);
            $result = $ocr->ocrOnePage($page->fresh());
            $pageStatus[$page->page_number] = array_merge(['ocr_status' => $result['status']], $result);

            if ($result['status'] === 'ok') {
                $this->line(sprintf('  page %d: OCR ok (quality=%s, cost=$%.4f)', $page->page_number, $result['quality'] ?? '—', (float) ($result['cost_usd'] ?? 0)));
            } else {
                $this->warn("  page {$page->page_number}: OCR {$result['status']} (".($result['reason'] ?? 'unknown').') — this page will be missing from the derived xlsx.');
            }
        }

        // ---- step 2 (of this command): read back each page's sidecar ---------
        $sheets = [];   // page_number => table_rows
        $notes = [];    // [page, type(title|footnote), lang, text]
        $skippedPages = [];
        foreach ($pages as $page) {
            $key = $this->sidecarKey($document->uuid, $page->page_number);
            if (! Storage::disk('s3')->exists($key)) {
                if (($pageStatus[$page->page_number]['ocr_status'] ?? null) !== 'no_image') {
                    $skippedPages[] = "page {$page->page_number}: no OCR sidecar at {$key}";
                }

                continue;
            }
            $sidecar = json_decode(Storage::disk('s3')->get($key), true) ?? [];
            $tableRows = $sidecar['table_rows'] ?? [];

            foreach ($sidecar['article_headers'] ?? [] as $header) {
                $notes[] = ['page' => $page->page_number, 'type' => 'title', 'lang' => null, 'text' => $header];
            }
            foreach ($sidecar['columns'] ?? [] as $column) {
                // Pinned table contract (review.md §1.6): a `columns` entry on a
                // table page is ALWAYS footnote/plus-line text, never grid data.
                $notes[] = ['page' => $page->page_number, 'type' => 'footnote', 'lang' => $column['language'] ?? null, 'text' => $column['text'] ?? ''];
            }

            if ($tableRows === []) {
                $skippedPages[] = "page {$page->page_number}: sidecar has zero table_rows (not a grid page)";

                continue;
            }
            $sheets[$page->page_number] = $tableRows;
        }

        foreach ($skippedPages as $s) {
            $this->warn("  {$s}");
        }

        if ($sheets === []) {
            $this->error('No table_rows on any page — nothing to write. Aborting (no .xlsx produced, no document row created).');

            return self::FAILURE;
        }

        // ---- step 3 (of this command): build the .xlsx ------------------------
        $spreadsheet = new Spreadsheet;
        $spreadsheet->removeSheetByIndex(0);
        $reviewRows = [];

        foreach ($sheets as $pageNumber => $tableRows) {
            $sheet = $spreadsheet->createSheet();
            $sheet->setTitle("Page {$pageNumber}");

            foreach ($tableRows as $r => $row) {
                foreach ($row as $c => $cell) {
                    // Every cell explicit STRING — never let Excel/PhpSpreadsheet
                    // reinterpret "1.968,28" as a number/date. Verbatim, as OCR
                    // read it (the hard constraint: no guessing, no normalizing).
                    $sheet->setCellValueExplicit([$c + 1, $r + 1], (string) ($cell ?? ''), DataType::TYPE_STRING);
                }
            }

            $page = $pages->firstWhere('page_number', $pageNumber);
            $header = $tableRows[0] ?? [];
            $reviewRows[] = [
                'page' => $pageNumber,
                'rows' => count($tableRows),
                'cols' => count($header),
                'quality' => $pageStatus[$pageNumber]['quality'] ?? ($page?->ocr_quality ?? '—'),
                'header' => implode(' | ', array_map(fn ($h) => (string) $h, $header)),
                'image_url' => $page ? $this->pageImageUrl($page) : null,
            ];
        }

        if ($notes !== []) {
            $notesSheet = $spreadsheet->createSheet();
            $notesSheet->setTitle('Notes');
            $notesSheet->setCellValueExplicit('A1', 'page', DataType::TYPE_STRING);
            $notesSheet->setCellValueExplicit('B1', 'type', DataType::TYPE_STRING);
            $notesSheet->setCellValueExplicit('C1', 'language', DataType::TYPE_STRING);
            $notesSheet->setCellValueExplicit('D1', 'text', DataType::TYPE_STRING);
            foreach ($notes as $i => $n) {
                $row = $i + 2;
                $notesSheet->setCellValueExplicit("A{$row}", (string) $n['page'], DataType::TYPE_STRING);
                $notesSheet->setCellValueExplicit("B{$row}", (string) $n['type'], DataType::TYPE_STRING);
                $notesSheet->setCellValueExplicit("C{$row}", (string) ($n['lang'] ?? ''), DataType::TYPE_STRING);
                $notesSheet->setCellValueExplicit("D{$row}", (string) $n['text'], DataType::TYPE_STRING);
            }
        }

        $localDir = storage_path("app/salary-derived/{$document->uuid}");
        if (! is_dir($localDir)) {
            mkdir($localDir, 0755, true);
        }
        $localPath = "{$localDir}/salary.xlsx";
        (new Xlsx($spreadsheet))->save($localPath);

        $s3Key = "documents/{$document->uuid}/derived/salary.xlsx";
        Storage::disk('s3')->put($s3Key, file_get_contents($localPath));

        // ---- step 4: the derived `documents` row (idempotent) -----------------
        $derived = Document::where('derived_from_document_id', $document->id)->first();
        if ($derived === null) {
            $derived = Document::create([
                'title' => "{$document->title} (salary grid — OCR-derived .xlsx)",
                'source_filename' => pathinfo((string) $document->source_filename, PATHINFO_FILENAME).'.derived.xlsx',
                'storage_path' => $s3Key,
                'content_hash' => hash('sha256', $s3Key.'|'.now()->toISOString()),
                'convenio_id' => $document->convenio_id,
                'document_type_id' => $document->document_type_id,
                'validity_start' => $document->validity_start,
                'validity_end' => $document->validity_end,
                'retrieval_status' => $document->retrieval_status,
                'authority_level' => $document->authority_level,
                'derived_from_document_id' => $document->id,
                'language' => $document->language,
                // Mechanically derived from an already-resolved source (same
                // convenio/type as the original) — no separate human tagging
                // pass; salary:import doesn't gate on tagging_status anyway.
                'tagging_status' => 'verified',
                'ingested_at' => now(),
            ]);
            $this->info("Created derived document [{$derived->id}] uuid={$derived->uuid} (derived_from_document_id={$document->id}).");
        } else {
            $derived->update(['content_hash' => hash('sha256', $s3Key.'|'.now()->toISOString())]);
            $this->info("Reusing existing derived document [{$derived->id}] uuid={$derived->uuid} — .xlsx overwritten in place.");
        }

        $this->newLine();
        $this->info("Derived .xlsx written:\n  s3://{$s3Key}\n  local: {$localPath}");
        $this->newLine();
        $this->info('Review table (compare each page against its image before approving):');
        $this->table(['page', 'rows', 'cols', 'quality', 'header (verbatim, col 1..N)', 'page image (10-min link)'], array_map(fn ($r) => [
            $r['page'], $r['rows'], $r['cols'], $r['quality'], mb_strimwidth($r['header'], 0, 90, '…'), $r['image_url'] ?? '—',
        ], $reviewRows));

        if ($skippedPages !== []) {
            $this->newLine();
            $this->warn('Page(s) NOT written to the .xlsx (see warnings above): '.implode('; ', $skippedPages));
        }

        $this->newLine();
        $this->line('Next steps (human-gated, not run by this command):');
        $this->line("  1. Review the .xlsx above against the original PDF (open the page image links).");
        $this->line("  2. On approval:  php artisan salary:import --document={$derived->uuid}");
        $this->line("  3. Then stamp provenance:  php artisan salary:pdf-to-xlsx --document={$document->uuid} --mark-provenance");

        return self::SUCCESS;
    }

    /**
     * Step 4 (separate invocation): stamp `source = 'ocr_pdf'` on every
     * `salary_tables` row `salary:import` wrote for this original document's
     * derived `.xlsx`. Never runs `salary:import` itself; errors (rather than
     * silently no-ops) if that hasn't happened yet.
     */
    private function markProvenance(Document $document): int
    {
        $derived = Document::where('derived_from_document_id', $document->id)->first();
        if ($derived === null) {
            $this->error("No derived .xlsx document found for {$document->uuid} — run `salary:pdf-to-xlsx --document={$document->uuid}` first.");

            return self::FAILURE;
        }

        $tables = SalaryTable::where('source_document_id', $derived->id)->get();
        if ($tables->isEmpty()) {
            $this->warn("No salary_tables rows reference the derived document [{$derived->id}] yet — has `salary:import --document={$derived->uuid}` been run and approved?");

            return self::FAILURE;
        }

        $tables->each(fn (SalaryTable $t) => $t->update(['source' => 'ocr_pdf']));
        $this->info(sprintf(
            "Marked %d salary_tables row(s) source=ocr_pdf (source_document_id=%d, derived from original document [%d] %s / %s).",
            $tables->count(), $derived->id, $document->id, $document->uuid, $document->source_filename,
        ));

        return self::SUCCESS;
    }

    /** The exact key `hr-ai/app/ocr.py`'s `ocr_sidecar_key()` writes to. */
    private function sidecarKey(string $documentUuid, int $pageNumber): string
    {
        return sprintf('documents/%s/ocr/%04d.json', $documentUuid, $pageNumber);
    }

    /**
     * Same mechanism as `DocumentController::pageImage()` — a 10-minute
     * pre-signed S3 URL. Never fatal: a disk that can't sign URLs (e.g. a
     * fake disk under test) degrades to a null link, not a crashed command —
     * this is a review convenience, not load-bearing for the .xlsx itself.
     */
    private function pageImageUrl(DocumentPage $page): ?string
    {
        if (empty($page->image_path)) {
            return null;
        }

        try {
            return Storage::disk('s3')->temporaryUrl($page->image_path, now()->addMinutes(10));
        } catch (\Throwable) {
            return null;
        }
    }
}
