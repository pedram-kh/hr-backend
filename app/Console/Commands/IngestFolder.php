<?php

namespace App\Console\Commands;

use App\Services\DocumentIngestor;
use App\Support\VocabularyResolver;
use Illuminate\Console\Command;
use Symfony\Component\Finder\Finder;

/**
 * Bulk-ingest a province-foldered corpus from local disk, reusing the EXACT
 * Sprint-1 ingestion machinery (DocumentIngestor → hr-ai /extract → S3 → DB,
 * filename parser + conflict/review). This is an ops convenience over the
 * HTTP folder-upload endpoint for the ~90-file real corpus; it does not
 * reimplement tagging.
 *
 * Accepts PDF prose + salary .xlsx (ADR-0014). Ignores (already decided):
 * __MACOSX/, CONVENIOS 2026.xls, the loose .doc/.docx (out of scope this
 * sprint). The loose Plan_Igualdad_texto.pdf is ingested normally (lands
 * under_review by design — not special-cased).
 */
class IngestFolder extends Command
{
    protected $signature = 'documents:ingest-folder
        {path? : corpus root (default data/all-files)}
        {--ocr : Sprint 7e (ADR-0026) opt-in OCR fallback for text-less PDF pages (default off)}
        {--ocr-page-cap= : per-document OCR page cap (default services.hr_ai.ocr_page_cap)}
        {--retype : Sprint 7g Item 3 (F-1) — explicitly confirm a checksum-matched file may change document_type/convenio/validity on the existing document. Default off: such a collision is reported and the document is left untouched.}';

    protected $description = 'Ingest a province-foldered PDF + salary .xlsx corpus, reusing the Sprint-1 ingestor.';

    public function handle(DocumentIngestor $ingestor): int
    {
        $root = $this->argument('path') ?? base_path('data/all-files');
        if (! is_dir($root)) {
            $this->error("Corpus folder not found: {$root}");

            return self::FAILURE;
        }
        $root = rtrim($root, '/');

        // Sprint 7e (ADR-0026, review.md §2.7): opt-in, off by default — omitting
        // --ocr reproduces the exact pre-7e behavior on every PDF page.
        $ocr = $this->option('ocr');
        $ocrPageCap = $this->option('ocr-page-cap') !== null
            ? (int) $this->option('ocr-page-cap')
            : (int) config('services.hr_ai.ocr_page_cap');
        if ($ocr) {
            $this->info("OCR fallback ON — page cap {$ocrPageCap}/document.");
        }

        $finder = (new Finder)->files()->in($root)->ignoreDotFiles(true);
        $vocab = new VocabularyResolver;
        $retype = $this->option('retype');

        $ingested = 0;
        $skipped = 0;
        $errors = 0;
        $retypeBlocked = 0;

        foreach ($finder as $file) {
            $rel = ltrim(str_replace($root, '', $file->getRealPath()), '/');
            $name = $file->getFilename();
            $ext = strtolower($file->getExtension());

            // Ignore rules (already decided).
            if (str_contains($rel, '__MACOSX') || $name === '.DS_Store') {
                continue;
            }
            if ($name === 'CONVENIOS 2026.xls') {
                $this->line("  skip (status note, not a registry): {$rel}");
                $skipped++;

                continue;
            }
            if (! in_array($ext, ['pdf', 'xlsx'], true)) {
                $this->line("  skip (out of scope format .{$ext}): {$rel}");
                $skipped++;

                continue;
            }

            $folderLabel = $this->topFolder($rel);
            try {
                $result = $ingestor->ingest(
                    $file->getRealPath(),
                    $name,
                    $folderLabel,
                    $rel,
                    null,
                    $vocab,
                    false,
                    $ocr,
                    $ocrPageCap,
                    $retype,
                );
                // Sprint 7g Item 3 (F-1): a checksum match that would silently
                // re-type/re-scope the existing document — reported, nothing
                // written, NOT counted as an error (this is expected, correct
                // behavior of the safety gate, not a failure).
                if ($result['confirm_scope_change_required'] ?? false) {
                    $retypeBlocked++;
                    $this->warn("  BLOCKED (re-run with --retype to confirm): {$result['message']}");

                    continue;
                }
                $ingested++;
                $flag = $result['tagging_status'] === 'under_review' ? ' [UNDER_REVIEW '.$result['review_reason'].']' : '';
                $this->line("  ingested ({$result['tagging_status']}{$flag}): {$rel}");
            } catch (\Throwable $e) {
                $errors++;
                $this->error("  ERROR {$rel}: ".$e->getMessage());
            }
        }

        $this->newLine();
        $this->info("Ingest complete: {$ingested} ingested, {$skipped} skipped, {$retypeBlocked} retype-blocked (checksum match, use --retype to confirm), {$errors} errors.");

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function topFolder(string $relativePath): ?string
    {
        $parts = array_values(array_filter(explode('/', $relativePath)));
        array_pop($parts); // drop filename

        return $parts[0] ?? null;
    }
}
