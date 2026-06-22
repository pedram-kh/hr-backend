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
    protected $signature = 'documents:ingest-folder {path? : corpus root (default data/all-files)}';

    protected $description = 'Ingest a province-foldered PDF + salary .xlsx corpus, reusing the Sprint-1 ingestor.';

    public function handle(DocumentIngestor $ingestor): int
    {
        $root = $this->argument('path') ?? base_path('data/all-files');
        if (! is_dir($root)) {
            $this->error("Corpus folder not found: {$root}");

            return self::FAILURE;
        }
        $root = rtrim($root, '/');

        $finder = (new Finder)->files()->in($root)->ignoreDotFiles(true);
        $vocab = new VocabularyResolver;

        $ingested = 0;
        $skipped = 0;
        $errors = 0;

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
                );
                $ingested++;
                $flag = $result['tagging_status'] === 'under_review' ? ' [UNDER_REVIEW '.$result['review_reason'].']' : '';
                $this->line("  ingested ({$result['tagging_status']}{$flag}): {$rel}");
            } catch (\Throwable $e) {
                $errors++;
                $this->error("  ERROR {$rel}: ".$e->getMessage());
            }
        }

        $this->newLine();
        $this->info("Ingest complete: {$ingested} ingested, {$skipped} skipped, {$errors} errors.");

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function topFolder(string $relativePath): ?string
    {
        $parts = array_values(array_filter(explode('/', $relativePath)));
        array_pop($parts); // drop filename

        return $parts[0] ?? null;
    }
}
