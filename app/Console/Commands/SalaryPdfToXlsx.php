<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Models\DocumentPage;
use App\Models\SalaryTable;
use App\Models\SalaryTableRow;
use App\Services\ExtractionClient;
use App\Services\OcrService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
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
 *     fallback; ADR-0026 §2.2/§2.3).
 *  2. Reads each page's `table_rows` back out. When a page's grid is a
 *     genuine BILINGUAL DUPLICATE of the same table (found live on docs
 *     12/27/57 — the source gazette prints the identical categories twice,
 *     once `eu` once `es`, stacked in one grid), drops the rows preceding
 *     the `es` table's own header row (Pedram's approved decision, review.md
 *     §5 addendum) — logged verbatim to the `Notes` sheet, never silently
 *     discarded. Every surviving cell is written EXPLICIT STRING, verbatim
 *     — no normalization, no type coercion — to one sheet per page of a
 *     derived `.xlsx` at `documents/{uuid}/derived/salary.xlsx` (S3 + local).
 *  3. Prints a per-page review table (row/col counts, header preview, a
 *     10-minute pre-signed link to that page's source image) AND a header-
 *     mapping PROPOSAL (this header cell vs. `salary.py`'s existing exact-
 *     match canonical vocabulary, §`CANONICAL_VOCAB` below — a READ-ONLY
 *     mirror kept only to PROPOSE a mapping to a human; `salary.py` itself
 *     is never touched, never made fuzzier). Proposes nothing where doing so
 *     would misrepresent a MONTHLY figure as the ANNUAL field `salary.py`
 *     computes `base_salary_monthly` FROM (the `gross_annual`/14 rule) —
 *     flagged `unsafe`, not proposed, a human call.
 *  4. Creates (or reuses) ONE `documents` row for the derived `.xlsx`
 *     artifact — linked back via `derived_from_document_id` — so the
 *     EXISTING, UNMODIFIED `salary:import --document=<derived-uuid>` finds
 *     it via its own `storage_path like '%.xlsx'` query, no code change.
 *
 * `--apply-header-mapping`: A SEPARATE, human-approved invocation. Mutates
 * ONLY the header row (row 1) of each already-written sheet in the derived
 * `.xlsx` already on S3/local — replacing a cell ONLY where step 3 proposed
 * one — and logs original → proposed, the exact qualifiers dropped, and a
 * machine-readable manifest to the `Notes` sheet. Data rows are NEVER touched
 * by this or any other flag.
 *
 * Sheet names carry the YEAR, not the page ("2025 (page 2)"): `salary.py`'s
 * `_year_from_sheet_name()` is where `salary:import` gets the year it keys
 * `salary_tables` on, and a year-less name makes every table of one convenio
 * collide onto a single row (a 2026 import silently deleting the 2025 rows).
 * The year is read from the page's own OCR'd title first, never inferred from
 * the numbers, and where it came from is recorded on the `Notes` sheet.
 *
 * `--verify`: read-only. Calls `ExtractionClient::extractSalary()` (the same
 * hr-ai call `salary:import` itself makes) against the derived `.xlsx` and
 * prints its `tables`/`warnings` — proving whether the grid is now detected,
 * WITHOUT writing a single DB row.
 *
 * `--mark-provenance`: the FOURTH, separate, human-gated step. Run only
 * AFTER a reviewed `salary:import` has already written rows for the derived
 * document — stamps `salary_tables.source = 'ocr_pdf'` on exactly those rows,
 * AND restores each mapped column's ORIGINAL, verbatim source header text as
 * an additional `raw_values` key on every imported row (the importer's own
 * normalized key is left in place). Without this, a mapped column's source
 * wording — e.g. the "(sin antigüedad)" caveat — exists nowhere in the DB.
 *
 * This command itself NEVER calls `salary:import` and NEVER writes a
 * `salary_tables`/`salary_table_rows` row.
 */
class SalaryPdfToXlsx extends Command
{
    protected $signature = 'salary:pdf-to-xlsx
        {--document= : the salary_tables PDF document uuid}
        {--apply-header-mapping : mutate ONLY the header row of the already-written derived .xlsx per the proposed mapping (human-approved)}
        {--verify : read-only — call hr-ai\'s extract-salary against the derived xlsx and print tables/warnings, no DB write}
        {--mark-provenance : after a human-approved salary:import run against the derived xlsx, stamp its salary_tables row(s) source=ocr_pdf}';

    protected $description = 'OCR a salary_tables PDF\'s table pages and write a derived .xlsx for the existing salary:import path (Sprint 7e follow-up, Option A). Never imports, never writes salary_tables rows itself.';

    /**
     * READ-ONLY mirror of hr-ai's `app/salary.py` `_GROSS`/`_HOURLY`/
     * `_EXTRA`/`_NIGHT`/`_RAW_MONEY` (its exact-match header-synonym sets) —
     * kept ONLY so this command can PROPOSE a header-cell mapping to a
     * human. Never fed back into `salary.py`; if that file's sets ever
     * change, this mirror can drift stale (a proposal, not a guarantee) —
     * `--verify` (calling the REAL parser) is the source of truth, not this.
     */
    private const CANONICAL_VOCAB = [
        'gross_annual' => ['total', 'total anual', 'bruto anual', 'bruto ano', 'importe anual', 'salario anual'],
        'hourly_rate' => ['€/hora', 'e/hora', 'euro/hora', 'euros/hora', 'hora', 'precio hora', 'precio/hora', 'coste hora', '/hora', 'bruto/hora', 'bruto hora', 'salario hora'],
        'extra_pay' => ['pagas extra', 'paga extra', 'pagas extras', 'paga extras'],
        'night_plus' => ['plus nocturno', 'nocturnidad', 'plus noche', 'nocturno', 'plus nocturnidad', 'plus hora nocturna', 'plus hora noctur', 'hora nocturna'],
        // salary.py's own docs: these ANCHOR the money-column boundary (so
        // the label columns are correctly separated from the numeric grid)
        // but are NEVER themselves typed onto a salary_table_rows column —
        // `base_salary_monthly` is COMPUTED elsewhere as `gross_annual / 14`,
        // never read directly off a "salario base" column.
        'untyped_anchor' => ['sb', 'sb anual', 'comp', 'comp.', 'comp smi', 'comp. smi', 'comp smi / ano', 'comp smi / mes', '14', '12', 'bruto mes', 'bruto/mes 14 pagas', 'bruto/mes 12 pagas', 'salario base', 'dedica', 'pc', 'paga 16', 'p.p.paga extra', 'plus transporte', 'plus tpte/dia', 'plus tpte/día', '5% mejora sedena', '1,2,3,5 quinquenio', '4 quinquenio', 'quinquenio', 'antiguedad'],
    ];

    /** Period words whose loss would change a figure's meaning (the "(mes)/(año)" rule). */
    private const MONTHLY_WORDS = ['mes', 'mensual', 'hil', 'hilabete'];

    private const ANNUAL_WORDS = ['ano', 'anual', 'urteko', 'urtean'];

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
        if ($this->option('verify')) {
            return $this->verify($document);
        }
        if ($this->option('apply-header-mapping')) {
            return $this->applyHeaderMapping($document);
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

        // ---- step 2: read back each page's sidecar, drop a bilingual dup -----
        $sheets = [];   // page_number => table_rows (post-dedup)
        $years = [];    // page_number => [year|null, provenance]
        $notes = [];    // [page, type, lang, text]
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

            $tableRows = $this->dropBilingualDuplicateRows($tableRows, $page->page_number, $notes);
            $sheets[$page->page_number] = $tableRows;
            $years[$page->page_number] = $this->resolveSheetYear($document, $sidecar);
        }

        foreach ($skippedPages as $s) {
            $this->warn("  {$s}");
        }

        if ($sheets === []) {
            $this->error('No table_rows on any page — nothing to write. Aborting (no .xlsx produced, no document row created).');

            return self::FAILURE;
        }

        // ---- step 3: build the .xlsx + the header-mapping proposal ------------
        $spreadsheet = new Spreadsheet;
        $spreadsheet->removeSheetByIndex(0);
        $reviewRows = [];
        $mappingProposals = []; // sheet name => [ per-column proposal ]

        foreach ($sheets as $pageNumber => $tableRows) {
            [$year, $yearFrom] = $years[$pageNumber] ?? [null, null];
            $sheetName = $this->sheetName($pageNumber, $year);
            $sheet = $spreadsheet->createSheet();
            $sheet->setTitle($sheetName);

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
                'sheet' => $sheetName,
                'year' => $year ?? '⚠ none',
                'rows' => count($tableRows),
                'cols' => count($header),
                'quality' => $pageStatus[$pageNumber]['quality'] ?? ($page?->ocr_quality ?? '—'),
                'header' => implode(' | ', array_map(fn ($h) => (string) $h, $header)),
                'image_url' => $page ? $this->pageImageUrl($page) : null,
            ];

            // The year is NEVER invented: it comes from the page's own OCR'd
            // title, else the document's own title/filename/validity — and
            // where it came from is recorded on the Notes sheet, because
            // `salary:import` keys `salary_tables` on (convenio_id, year) and
            // reads that year from the SHEET NAME (salary.py's
            // `_year_from_sheet_name`), not from anything inside the grid.
            $notes[] = [
                'page' => $pageNumber,
                'type' => 'sheet_year_provenance',
                'lang' => null,
                'text' => $year === null
                    ? "sheet '{$sheetName}': NO year found on the page title, the document title/filename, or validity_start — salary:import will write this table with year = NULL, which collides with every other year-less table of the same convenio. Fix the source metadata before importing."
                    : "sheet '{$sheetName}': year {$year}, taken verbatim from {$yearFrom}. salary:import reads the year from this sheet name.",
            ];

            $mappingProposals[$sheetName] = array_map(fn ($h) => $this->proposeHeaderMapping((string) $h), $header);
        }

        $this->warnOnYearCollisions($reviewRows);

        if ($notes !== []) {
            $this->writeNotesSheet($spreadsheet, $notes);
        }

        $localPath = $this->localPath($document->uuid);
        (new Xlsx($spreadsheet))->save($localPath);

        $s3Key = $this->s3Key($document->uuid);
        Storage::disk('s3')->put($s3Key, file_get_contents($localPath));

        // ---- step 4: the derived `documents` row (idempotent) -----------------
        $derived = $this->findOrCreateDerivedDocument($document, $s3Key);

        $this->newLine();
        $this->info("Derived .xlsx written:\n  s3://{$s3Key}\n  local: {$localPath}");
        $this->newLine();
        $this->info('Review table (compare each page against its image before approving):');
        $this->table(['page', 'sheet', 'year', 'rows', 'cols', 'quality', 'header (verbatim, col 1..N)', 'page image (10-min link)'], array_map(fn ($r) => [
            $r['page'], $r['sheet'], $r['year'], $r['rows'], $r['cols'], $r['quality'], mb_strimwidth($r['header'], 0, 90, '…'), $r['image_url'] ?? '—',
        ], $reviewRows));

        if ($skippedPages !== []) {
            $this->newLine();
            $this->warn('Page(s) NOT written to the .xlsx (see warnings above): '.implode('; ', $skippedPages));
        }

        $this->printMappingProposals($mappingProposals);

        $this->newLine();
        $this->line('Next steps (human-gated, not run by this command):');
        $this->line('  1. Review the .xlsx above against the original PDF (open the page image links) and the header-mapping proposal above.');
        $this->line("  2. On approval of the mapping:  php artisan salary:pdf-to-xlsx --document={$document->uuid} --apply-header-mapping");
        $this->line("  3. Confirm it's now detected (read-only):  php artisan salary:pdf-to-xlsx --document={$document->uuid} --verify");
        $this->line("  4. Then import:  php artisan salary:import --document={$derived->uuid}");
        $this->line("  5. Then stamp provenance:  php artisan salary:pdf-to-xlsx --document={$document->uuid} --mark-provenance");

        return self::SUCCESS;
    }

    /**
     * Pedram's approved decision (review.md §5 addendum): when a page's grid
     * is the SAME table printed twice — once in another language, once in
     * `es` — keep only the `es` table (the rows from its own header row,
     * whose first cell normalizes to exactly "categoria", onward). Rows
     * before that point are DROPPED from the grid but never silently lost —
     * logged verbatim to the `Notes` sheet. A no-op whenever no second
     * "categoria" row exists (the normal, non-duplicated case, e.g. a sheet
     * that was already `es`-only from row 0).
     */
    private function dropBilingualDuplicateRows(array $tableRows, int $pageNumber, array &$notes): array
    {
        $splitAt = null;
        foreach ($tableRows as $i => $row) {
            if ($i === 0) {
                continue; // row 0 is itself a header candidate — only a SECOND occurrence marks a duplicate.
            }
            if ($this->normalizeHeaderText((string) ($row[0] ?? '')) === 'categoria') {
                $splitAt = $i;
                break;
            }
        }

        if ($splitAt === null) {
            return $tableRows;
        }

        $dropped = array_slice($tableRows, 0, $splitAt);
        $kept = array_slice($tableRows, $splitAt);
        $notes[] = [
            'page' => $pageNumber,
            'type' => 'dropped_bilingual_duplicate',
            'lang' => null,
            'text' => sprintf(
                'Dropped %d row(s) preceding the es table (kept only from "Categoría" onward — approved decision, review.md §5): %s',
                count($dropped),
                json_encode($dropped, JSON_UNESCAPED_UNICODE),
            ),
        ];

        return $kept;
    }

    /**
     * The year `salary:import` will file this sheet's table under — read from
     * the page's OWN OCR'd title first (the source's own declaration for that
     * grid, e.g. "TABLAS SALARIALES 2025"), then the document's title, its
     * source filename, and finally `validity_start`. Never inferred from the
     * grid's numbers, never defaulted to "now": if none of those carry a year,
     * this returns null and the command says so loudly, because `salary.py`'s
     * `_year_from_sheet_name()` would then hand `salary:import` a NULL year —
     * and `SalaryTable::updateOrCreate(['convenio_id','year'])` makes every
     * year-less table of one convenio the SAME row (the 2026 import would
     * delete the 2025 rows).
     *
     * @return array{0:int|null,1:string|null} [year, where it came from]
     */
    private function resolveSheetYear(Document $document, array $sidecar): array
    {
        // Same pattern salary.py's own `_year_from_sheet_name()` uses, so what
        // this reads and what the importer reads can never disagree.
        $find = fn (?string $haystack) => preg_match('/(19|20)\d{2}/', (string) $haystack, $m) ? (int) $m[0] : null;

        foreach ($sidecar['article_headers'] ?? [] as $header) {
            if ($year = $find((string) $header)) {
                return [$year, "the page's own OCR'd title \"{$header}\""];
            }
        }
        if ($year = $find($document->title)) {
            return [$year, "the document title \"{$document->title}\""];
        }
        if ($year = $find($document->source_filename)) {
            return [$year, "the source filename \"{$document->source_filename}\""];
        }
        if ($document->validity_start) {
            return [(int) $document->validity_start->format('Y'), 'documents.validity_start'];
        }

        return [null, null];
    }

    /** "2025 (page 2)" — the year first, because salary.py reads it out of this string. */
    private function sheetName(int $pageNumber, ?int $year): string
    {
        return $year === null ? "Page {$pageNumber}" : "{$year} (page {$pageNumber})";
    }

    /**
     * Two sheets of one workbook resolving to the SAME year is not fatal here,
     * but it IS silent data loss at import time (`updateOrCreate` on
     * (convenio_id, year) makes them one row, and the second sheet's rows
     * replace the first's) — so it is warned about, never left implicit.
     */
    private function warnOnYearCollisions(array $reviewRows): void
    {
        $byYear = [];
        foreach ($reviewRows as $r) {
            $byYear[(string) $r['year']][] = $r['sheet'];
        }
        foreach ($byYear as $year => $sheetNames) {
            if (count($sheetNames) > 1) {
                $this->warn(sprintf(
                    '  Sheets %s all resolve to year %s — salary:import keys salary_tables on (convenio_id, year), so importing this workbook as-is would keep only the LAST of them. Split or re-label before importing.',
                    implode(', ', array_map(fn ($s) => "'{$s}'", $sheetNames)),
                    $year,
                ));
            }
        }
    }

    /**
     * Propose a mapping from an OCR'd header cell to `salary.py`'s existing
     * exact-match canonical vocabulary — NEVER applied here, only computed
     * and returned for a human to see (§3 above) or, once approved, for
     * `--apply-header-mapping` to apply verbatim to the SAME cell.
     *
     * Cascade: (0) already an exact match — nothing to propose. (1) strip
     * ONLY currency/unit parens (e.g. "(€)", "(€/hora)") and re-check. (2)
     * strip ALL parens (currency + semantic, e.g. "(mes)", "(sin
     * antigüedad)") and re-check — flagged when a semantic qualifier is
     * dropped. (3) does any canonical term appear as a substring/token of
     * the fully-stripped text (longest term wins)? (4) no match — stays
     * untyped either way.
     *
     * Safety rule (the "(mes)/(año) meaning" instruction): a proposal that
     * would drop a MONTHLY qualifier while mapping onto `gross_annual`
     * (which `salary.py` treats as strictly annual — it's the figure
     * `base_salary_monthly` is computed FROM, via /14) is never proposed —
     * returned `unsafe`, a human call, not this command's.
     */
    private function proposeHeaderMapping(string $raw): array
    {
        $normalized = $this->normalizeHeaderText($raw);
        if ($normalized === '') {
            return $this->noProposal($raw, 'empty header cell.');
        }

        if ($field = $this->matchCanonicalExact($normalized)) {
            return [
                'original' => $raw, 'matches_as_is' => true, 'proposed' => null,
                'target_field' => $field, 'dropped' => [], 'unsafe' => false,
                'note' => "already matches hr-ai's existing exact-match header detection ({$field}) — no change needed.",
            ];
        }

        preg_match_all('/\(([^)]*)\)/u', $raw, $m);
        $groups = array_map(fn ($g) => [
            'text' => "({$g})",
            'kind' => $this->isCurrencyOrUnitParen($g) ? 'currency' : 'semantic',
        ], $m[1] ?? []);

        // Tier 1: strip currency/unit parens only.
        $currencyOnly = array_values(array_filter($groups, fn ($g) => $g['kind'] === 'currency'));
        if ($currencyOnly !== []) {
            $stripped = $this->stripParenGroups($raw, $currencyOnly);
            if ($field = $this->matchCanonicalExact($this->normalizeHeaderText($stripped))) {
                return $this->finalizeProposal($raw, $stripped, $field, $currencyOnly);
            }
        }

        // Tier 2: strip every paren (currency + semantic).
        if ($groups !== []) {
            $stripped = $this->stripParenGroups($raw, $groups);
            if ($field = $this->matchCanonicalExact($this->normalizeHeaderText($stripped))) {
                return $this->finalizeProposal($raw, $stripped, $field, $groups);
            }

            // Tier 3: does a canonical term appear as a substring of the fully-stripped text?
            $normStripped = $this->normalizeHeaderText($stripped);
            if ($term = $this->longestCanonicalSubstring($normStripped)) {
                [$field, $text] = $term;

                return $this->finalizeProposal($raw, $this->titleCase($text), $field, $groups);
            }
        }

        return $this->noProposal($raw, 'no canonical vocabulary term found, even after stripping units/qualifiers — stays untyped (raw_values only) either way.');
    }

    private function noProposal(string $raw, string $note): array
    {
        return ['original' => $raw, 'matches_as_is' => false, 'proposed' => null, 'target_field' => null, 'dropped' => [], 'unsafe' => false, 'note' => $note];
    }

    private function finalizeProposal(string $raw, string $proposedText, string $field, array $droppedGroups): array
    {
        $proposedText = trim($proposedText);
        $droppedSemanticNorm = array_map(
            fn ($g) => $this->normalizeHeaderText($g['text']),
            array_values(array_filter($droppedGroups, fn ($g) => $g['kind'] === 'semantic')),
        );

        $unsafe = false;
        if ($field === 'gross_annual') {
            foreach ($droppedSemanticNorm as $d) {
                foreach (self::MONTHLY_WORDS as $mw) {
                    if (str_contains($d, $mw)) {
                        $unsafe = true;
                    }
                }
            }
        }

        $droppedTexts = array_map(fn ($g) => "{$g['text']} [{$g['kind']}]", $droppedGroups);

        if ($unsafe) {
            return [
                'original' => $raw, 'matches_as_is' => false, 'proposed' => null,
                'target_field' => $field, 'dropped' => $droppedTexts, 'unsafe' => true,
                'note' => "UNSAFE, NOT proposed: dropping a MONTHLY qualifier would map a monthly figure onto '{$field}', which salary.py treats as strictly ANNUAL. A human call, not this command's.",
            ];
        }

        $note = $droppedGroups === []
            ? 'exact match after normalization alone (nothing dropped).'
            : 'drops '.implode(', ', $droppedTexts).' to reach an exact canonical match on hr-ai\'s existing detector.';

        return [
            'original' => $raw, 'matches_as_is' => false, 'proposed' => $proposedText,
            'target_field' => $field, 'dropped' => $droppedTexts, 'unsafe' => false, 'note' => $note,
        ];
    }

    /** A currency/unit-symbol parenthetical (e.g. "€", "€/hora", "€/orduko") vs. a semantic qualifier (e.g. "mes", "sin antigüedad"). */
    private function isCurrencyOrUnitParen(string $inner): bool
    {
        $t = trim($inner);

        return str_contains($t, '€') || (bool) preg_match('/^[\/\p{L}]*hora[\/\p{L}]*$/ui', $t);
    }

    private function stripParenGroups(string $raw, array $groups): string
    {
        $out = $raw;
        foreach ($groups as $g) {
            $out = str_replace($g['text'], '', $out);
        }
        $out = str_replace("\n", ' ', $out);
        $out = preg_replace('/\s+/u', ' ', $out);

        return trim((string) $out);
    }

    private function matchCanonicalExact(string $normalized): ?string
    {
        foreach (self::CANONICAL_VOCAB as $field => $terms) {
            foreach ($terms as $term) {
                if ($normalized === $this->normalizeHeaderText($term)) {
                    return $field;
                }
            }
        }

        return null;
    }

    /** @return array{0:string,1:string}|null [field, matched_term] — longest term wins. */
    private function longestCanonicalSubstring(string $normalized): ?array
    {
        $candidates = [];
        foreach (self::CANONICAL_VOCAB as $field => $terms) {
            foreach ($terms as $term) {
                $normTerm = $this->normalizeHeaderText($term);
                if ($normTerm !== '' && str_contains($normalized, $normTerm)) {
                    $candidates[] = [$field, $normTerm];
                }
            }
        }
        if ($candidates === []) {
            return null;
        }
        usort($candidates, fn ($a, $b) => mb_strlen($b[1]) - mb_strlen($a[1]));

        return $candidates[0];
    }

    private function titleCase(string $s): string
    {
        return mb_strtoupper(mb_substr($s, 0, 1)).mb_substr($s, 1);
    }

    /** Mirrors hr-ai's `salary.py::_norm()` exactly (accent-strip, lowercase, collapse whitespace, trim). */
    private function normalizeHeaderText(?string $raw): string
    {
        if ($raw === null || $raw === '') {
            return '';
        }
        $s = str_replace("\n", ' ', $raw);
        $decomposed = \Normalizer::normalize($s, \Normalizer::FORM_D);
        $s = $decomposed !== false ? $decomposed : $s;
        $s = preg_replace('/[\x{0300}-\x{036f}]/u', '', $s);
        $s = preg_replace('/\s+/u', ' ', (string) $s);
        $s = mb_strtolower(trim((string) $s));

        return trim($s, " .:\u{00b7}-");
    }

    private function printMappingProposals(array $mappingProposals): void
    {
        $this->newLine();
        $this->info('Header-mapping proposal (salary.py itself is NEVER changed — this only proposes what to write into the header ROW of the .xlsx before import; --apply-header-mapping applies it, on your approval):');
        foreach ($mappingProposals as $sheetName => $columns) {
            $this->line("  Sheet '{$sheetName}':");
            $this->table(['OCR\'d header cell (verbatim)', 'proposed', 'target field', 'dropped', 'note'], array_map(fn ($p) => [
                $p['original'],
                $p['matches_as_is'] ? '(unchanged)' : ($p['proposed'] ?? '—'),
                $p['target_field'] ?? '—',
                $p['dropped'] === [] ? '—' : implode(', ', $p['dropped']),
                $p['unsafe'] ? '⚠ '.$p['note'] : $p['note'],
            ], $columns));
        }
    }

    private function writeNotesSheet(Spreadsheet $spreadsheet, array $notes, bool $append = false): void
    {
        $notesSheet = $spreadsheet->getSheetByName('Notes');
        $startRow = 2;
        if ($notesSheet === null) {
            $notesSheet = $spreadsheet->createSheet();
            $notesSheet->setTitle('Notes');
            $notesSheet->setCellValueExplicit('A1', 'page', DataType::TYPE_STRING);
            $notesSheet->setCellValueExplicit('B1', 'type', DataType::TYPE_STRING);
            $notesSheet->setCellValueExplicit('C1', 'language', DataType::TYPE_STRING);
            $notesSheet->setCellValueExplicit('D1', 'text', DataType::TYPE_STRING);
        } elseif ($append) {
            $startRow = $notesSheet->getHighestRow() + 1;
        }
        foreach ($notes as $i => $n) {
            $row = $startRow + $i;
            $notesSheet->setCellValueExplicit("A{$row}", (string) $n['page'], DataType::TYPE_STRING);
            $notesSheet->setCellValueExplicit("B{$row}", (string) $n['type'], DataType::TYPE_STRING);
            $notesSheet->setCellValueExplicit("C{$row}", (string) ($n['lang'] ?? ''), DataType::TYPE_STRING);
            $notesSheet->setCellValueExplicit("D{$row}", (string) $n['text'], DataType::TYPE_STRING);
        }
    }

    private function findOrCreateDerivedDocument(Document $document, string $s3Key): Document
    {
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

        return $derived;
    }

    /**
     * Human-approved, SEPARATE step: mutate ONLY the header row (row 1) of
     * each "Page N" sheet in the ALREADY-WRITTEN derived .xlsx, replacing a
     * cell ONLY where {@see proposeHeaderMapping()} found a (safe) proposal
     * — re-derives the SAME deterministic proposal from the cell's current
     * text, so this is idempotent (re-running after an apply is a no-op:
     * every proposed cell now `matches_as_is`). Data rows are never touched.
     */
    private function applyHeaderMapping(Document $document): int
    {
        $derived = Document::where('derived_from_document_id', $document->id)->first();
        if ($derived === null) {
            $this->error("No derived .xlsx document found for {$document->uuid} — run `salary:pdf-to-xlsx --document={$document->uuid}` first.");

            return self::FAILURE;
        }

        $spreadsheet = $this->loadDerivedSpreadsheet($document);
        if ($spreadsheet === null) {
            $this->error('No derived .xlsx found locally or in S3 — run `salary:pdf-to-xlsx --document='.$document->uuid.'` first.');

            return self::FAILURE;
        }
        $localPath = $this->localPath($document->uuid);
        $applied = [];
        $manifest = [];
        foreach ($spreadsheet->getSheetNames() as $name) {
            if ($name === 'Notes') {
                continue;
            }
            $sheet = $spreadsheet->getSheetByName($name);
            $highestCol = $sheet->getHighestDataColumn(1);
            $highestColIdx = Coordinate::columnIndexFromString($highestCol);
            for ($c = 1; $c <= $highestColIdx; $c++) {
                $cell = $sheet->getCell([$c, 1]);
                $original = (string) $cell->getValue();
                $proposal = $this->proposeHeaderMapping($original);
                if ($proposal['matches_as_is'] || $proposal['proposed'] === null) {
                    continue; // nothing to apply — already fine, unsafe, or no match.
                }
                $sheet->setCellValueExplicit([$c, 1], $proposal['proposed'], DataType::TYPE_STRING);

                // The approval condition (Pedram, round 3): the qualifiers this
                // mapping drops are recorded here in full, and the ORIGINAL
                // header text is carried in the manifest below so
                // `--mark-provenance` can put it back into every imported row's
                // `raw_values` verbatim. Nothing about the source header is
                // recoverable from the xlsx alone once row 1 is rewritten —
                // this is what makes it recoverable.
                $applied[] = [
                    'page' => $name,
                    'type' => 'header_mapping_applied',
                    'lang' => null,
                    'text' => sprintf(
                        'col %d: "%s" -> "%s" (target=%s). Dropped qualifiers: %s. The original text above is preserved verbatim as a raw_values key by `--mark-provenance`.',
                        $c,
                        str_replace("\n", '\n', $original),
                        $proposal['proposed'],
                        $proposal['target_field'],
                        $proposal['dropped'] === [] ? 'none' : implode(', ', $proposal['dropped']),
                    ),
                ];
                $manifest[] = [
                    'sheet' => $name,
                    'col' => $c,
                    'original' => $original,
                    'proposed' => $proposal['proposed'],
                    // The exact key salary.py will use in raw_values for this
                    // column: it keys raw_values by the NORMALIZED header text
                    // (`_norm()`), not the raw cell.
                    'normalized_key' => $this->normalizeHeaderText($proposal['proposed']),
                    'target_field' => $proposal['target_field'],
                    'dropped' => $proposal['dropped'],
                ];
                $this->line("  {$name} col {$c}: \"".str_replace("\n", '\n', $original)."\" -> \"{$proposal['proposed']}\"");
            }
        }

        if ($applied === []) {
            $this->warn('No header cell needed a change (already matches, unsafe, or no match found) — .xlsx left untouched.');

            return self::SUCCESS;
        }

        $changeCount = count($applied);
        $applied[] = [
            'page' => '',
            'type' => 'header_mapping_manifest',
            'lang' => null,
            'text' => json_encode($manifest, JSON_UNESCAPED_UNICODE),
        ];
        $this->writeNotesSheet($spreadsheet, $applied, append: true);
        (new Xlsx($spreadsheet))->save($localPath);
        Storage::disk('s3')->put($this->s3Key($document->uuid), file_get_contents($localPath));

        $this->info(sprintf('Applied %d header-cell change(s) to s3://%s and the local copy. Next: --verify, then salary:import --document=%s.', $changeCount, $this->s3Key($document->uuid), $derived->uuid));

        return self::SUCCESS;
    }

    /**
     * Read-only. Calls the SAME hr-ai endpoint `salary:import` itself calls
     * (`ExtractionClient::extractSalary()`) against the derived .xlsx and
     * prints its `tables`/`warnings` verbatim. Writes NOTHING to the DB —
     * `extractSalary()` is hr-ai's extract-and-RETURN call (ADR-0010); only
     * `salary:import` ever persists its result.
     */
    private function verify(Document $document): int
    {
        $derived = Document::where('derived_from_document_id', $document->id)->first();
        if ($derived === null) {
            $this->error("No derived .xlsx document found for {$document->uuid} — run `salary:pdf-to-xlsx --document={$document->uuid}` first.");

            return self::FAILURE;
        }

        $result = app(ExtractionClient::class)->extractSalary($derived->storage_path, $derived->uuid);
        $tables = $result['tables'] ?? [];
        $this->info(sprintf('Read-only check via hr-ai\'s extractSalary() (NO db write): %d table(s) detected.', count($tables)));
        foreach ($tables as $t) {
            $this->line(sprintf("  sheet '%s': year=%s, %d row(s)", $t['sheet'] ?? '?', $t['year'] ?? '—', count($t['rows'] ?? [])));
        }
        foreach ($result['warnings'] ?? [] as $w) {
            $this->line("    · {$w}");
        }

        return $tables === [] ? self::FAILURE : self::SUCCESS;
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
            'Marked %d salary_tables row(s) source=ocr_pdf (source_document_id=%d, derived from original document [%d] %s / %s).',
            $tables->count(), $derived->id, $document->id, $document->uuid, $document->source_filename,
        ));

        $backfill = $this->restoreVerbatimHeadersInRawValues($document, $tables);
        if ($backfill['manifest'] === 0) {
            $this->line('  No header mapping was applied to this document — every raw_values key is already the source header verbatim, nothing to restore.');
        } else {
            $this->info(sprintf(
                '  Restored the verbatim source header on %d key(s) across %d salary_table_rows row(s), from %d mapped column(s): %s',
                $backfill['keys'], $backfill['rows'], $backfill['manifest'],
                implode('; ', array_map(fn ($m) => '"'.str_replace("\n", '\n', $m['original']).'" alongside "'.$m['normalized_key'].'"', $backfill['columns'])),
            ));
            $this->line('  (A later `salary:import` re-run rewrites raw_values from the sheet again — re-run --mark-provenance after any re-import to restore these keys.)');
        }

        return self::SUCCESS;
    }

    /**
     * The approval condition (Pedram, round 3): a mapped header column must
     * still carry its ORIGINAL, verbatim source header text in `raw_values`.
     *
     * `salary.py` keys `raw_values` by the NORMALIZED header cell it reads
     * (`_norm(header[c])`), so once `--apply-header-mapping` rewrites row 1 to
     * "Hora", the imported row only knows `"hora"` — the source's own
     * "Valor hora (sin antigüedad)\n(€/hora)", and with it the "excludes
     * seniority" caveat, is gone. This adds the original text back as an
     * ADDITIONAL key holding the same verbatim value (the importer's own key is
     * never removed or rewritten), using the manifest `--apply-header-mapping`
     * wrote to the Notes sheet. `salary:import` and `salary.py` stay untouched;
     * this runs after them, in the step that already exists for provenance.
     *
     * @param  Collection<int,SalaryTable>  $tables
     * @return array{rows:int,keys:int,manifest:int,columns:array<int,array<string,mixed>>}
     */
    private function restoreVerbatimHeadersInRawValues(Document $document, $tables): array
    {
        $empty = ['rows' => 0, 'keys' => 0, 'manifest' => 0, 'columns' => []];

        $spreadsheet = $this->loadDerivedSpreadsheet($document);
        if ($spreadsheet === null) {
            return $empty;
        }

        // Dedupe by original text: re-applying the mapping appends a fresh
        // manifest rather than rewriting the old one, and the same column must
        // only ever be restored once.
        $byOriginal = [];
        foreach ($this->readHeaderMappingManifest($spreadsheet) as $entry) {
            if (! empty($entry['original']) && ! empty($entry['normalized_key'])) {
                $byOriginal[$entry['original']] = $entry;
            }
        }
        $manifest = array_values($byOriginal);
        if ($manifest === []) {
            return $empty;
        }

        $rowsTouched = 0;
        $keysAdded = 0;
        foreach ($tables as $table) {
            foreach (SalaryTableRow::where('salary_table_id', $table->id)->get() as $row) {
                $raw = $row->raw_values ?? [];
                $changed = false;
                foreach ($manifest as $entry) {
                    if (array_key_exists($entry['original'], $raw)) {
                        continue; // already verbatim (idempotent re-run)
                    }
                    if (! array_key_exists($entry['normalized_key'], $raw)) {
                        continue; // this row had no value in that column
                    }
                    $raw[$entry['original']] = $raw[$entry['normalized_key']];
                    $keysAdded++;
                    $changed = true;
                }
                if ($changed) {
                    $row->update(['raw_values' => $raw]);
                    $rowsTouched++;
                }
            }
        }

        return ['rows' => $rowsTouched, 'keys' => $keysAdded, 'manifest' => count($manifest), 'columns' => $manifest];
    }

    private function loadDerivedSpreadsheet(Document $document): ?Spreadsheet
    {
        $localPath = $this->localPath($document->uuid);
        if (! is_file($localPath)) {
            $bytes = Storage::disk('s3')->get($this->s3Key($document->uuid));
            if ($bytes === null) {
                return null;
            }
            file_put_contents($localPath, $bytes);
        }

        return IOFactory::createReader('Xlsx')->load($localPath);
    }

    /** @return array<int,array<string,mixed>> the entries `--apply-header-mapping` recorded */
    private function readHeaderMappingManifest(Spreadsheet $spreadsheet): array
    {
        $notes = $spreadsheet->getSheetByName('Notes');
        if ($notes === null) {
            return [];
        }

        $entries = [];
        foreach ($notes->toArray(null, true, true, false) as $row) {
            if (($row[1] ?? null) !== 'header_mapping_manifest') {
                continue;
            }
            $decoded = json_decode((string) ($row[3] ?? ''), true);
            if (is_array($decoded)) {
                $entries = array_merge($entries, $decoded);
            }
        }

        return $entries;
    }

    /** The exact key `hr-ai/app/ocr.py`'s `ocr_sidecar_key()` writes to. */
    private function sidecarKey(string $documentUuid, int $pageNumber): string
    {
        return sprintf('documents/%s/ocr/%04d.json', $documentUuid, $pageNumber);
    }

    private function s3Key(string $documentUuid): string
    {
        return "documents/{$documentUuid}/derived/salary.xlsx";
    }

    private function localPath(string $documentUuid): string
    {
        $dir = storage_path("app/salary-derived/{$documentUuid}");
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        return "{$dir}/salary.xlsx";
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
