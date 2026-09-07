<?php

namespace App\Console\Commands;

use App\Jobs\ProposeDocumentTags;
use App\Models\Document;
use App\Models\DocumentPage;
use App\Services\OcrService;
use Illuminate\Console\Command;

/**
 * Sprint 7e Step 3 (ADR-0026, plan.md §4.1, review.md §2.1(2)) — OCR backfill
 * for ALREADY-INGESTED text-less documents (ingested before this feature
 * existed, or ingested with `--ocr` off). Modeled directly on
 * `IngestFolder`/`ChunksEmbed`'s query → act → report shape.
 *
 * A synchronous CLI loop, not a queued job — a CLI command run over SSH has
 * no web request deadline, the same posture `ChunksEmbed`'s synchronous
 * `/embed` call already takes ("model load + CPU embedding is a background
 * admin path"). This is what lets it print the per-document report (pages
 * OCR'd, mean/min quality, cost) in the same run, then dispatch the tag
 * re-suggest immediately after — no queue, no batch bookkeeping.
 *
 * NEVER calls `confirm()` (the "inert until verified" hard constraint) and
 * NEVER touches a document already `tagging_status = 'verified'`.
 */
class OcrBackfill extends Command
{
    protected $signature = 'documents:ocr-backfill
        {--document= : back-fill a single document by uuid}
        {--page-cap= : per-document OCR page cap (default services.hr_ai.ocr_page_cap)}
        {--dry-run : list the selection only}';

    protected $description = 'OCR-backfill already-ingested text-less documents (Sprint 7e, ADR-0026) — leaves every document under_review for human verification.';

    public function handle(OcrService $ocr): int
    {
        $pageCap = $this->option('page-cap') !== null
            ? (int) $this->option('page-cap')
            : (int) config('services.hr_ai.ocr_page_cap');

        // The exact plan.md §1.7 inventory query: every document with at least
        // one page, where NOT ONE of those pages has extractable text.
        $query = Document::query()
            ->whereHas('pages')
            ->whereDoesntHave('pages', fn ($q) => $q->whereRaw("length(btrim(coalesce(text, ''))) > 0"));

        if ($uuid = $this->option('document')) {
            $query->where('uuid', $uuid);
        }

        $documents = $query->orderBy('id')->get();
        $this->info("Text-less documents selected for OCR backfill: {$documents->count()} (page cap {$pageCap}/document)");

        if ($this->option('dry-run')) {
            foreach ($documents as $d) {
                $this->line("  [{$d->id}] {$d->tagging_status} — {$d->source_filename}");
            }

            return self::SUCCESS;
        }

        $totalCost = 0.0;
        $failedDocs = 0;
        $totalSkippedNoKey = 0;

        foreach ($documents as $document) {
            // Never re-open an already-verified document — OCR only fills
            // text-less pages that are still awaiting human review. A verified
            // document with somehow-empty text is a different, unrelated
            // problem (out of scope here — this command never touches it).
            if ($document->tagging_status === 'verified') {
                $this->line("  [{$document->id}] {$document->source_filename}: skip (already verified)");

                continue;
            }

            $pages = $document->pages()
                ->whereRaw("length(btrim(coalesce(text, ''))) = 0")
                ->limit($pageCap)
                ->get();

            if ($pages->isEmpty()) {
                continue;
            }

            // Mark eligible pages ocr_pending (bounded by the cap) — mirrors
            // exactly what hr-ai's /extract does at ingest time, just applied to
            // pages that already exist in the DB from a PRIOR ingest (this
            // command exists precisely for that case). Pages past the cap are
            // left untouched (still empty, not ocr_pending) — the same
            // "surfaced, not silently truncated" posture as ingest-time.
            DocumentPage::whereIn('id', $pages->pluck('id'))->update(['extraction_source' => 'ocr_pending']);

            $qualities = [];
            $cost = 0.0;
            $ocrd = 0;
            $errors = 0;
            $skipped = 0;
            $skipReasons = [];
            foreach ($pages as $page) {
                $result = $ocr->ocrOnePage($page->fresh());
                if ($result['status'] === 'ok') {
                    $ocrd++;
                    if ($result['quality'] !== null) {
                        $qualities[] = (float) $result['quality'];
                    }
                    $cost += (float) ($result['cost_usd'] ?? 0);
                } elseif ($result['status'] === 'skipped') {
                    // Distinct from a real provider/transport failure (below) —
                    // e.g. `answer_model_not_configured`: no call was ever made,
                    // nothing failed, the page is just left ocr_pending exactly
                    // as it was. Counting this as an "error" would be actively
                    // misleading (it reads as "OCR tried and failed" when
                    // nothing was attempted at all).
                    $skipped++;
                    $skipReasons[$result['reason'] ?? 'unknown'] = true;
                    if (($result['reason'] ?? null) === 'answer_model_not_configured') {
                        $totalSkippedNoKey++;
                    }
                } else {
                    $errors++;
                }
            }
            $totalCost += $cost;
            if ($errors > 0) {
                $failedDocs++;
            }

            $meanQ = $qualities !== [] ? round(array_sum($qualities) / count($qualities), 3) : null;
            $minQ = $qualities !== [] ? round(min($qualities), 3) : null;
            $skipNote = $skipped > 0 ? sprintf(', %d skipped (%s)', $skipped, implode(',', array_keys($skipReasons))) : '';
            $this->line(sprintf(
                "  [%d] %s: %d/%d pages OCR'd (%d error(s)%s); quality mean=%s min=%s; cost=\$%.4f",
                $document->id,
                $document->source_filename,
                $ocrd,
                $pages->count(),
                $errors,
                $skipNote,
                $meanQ ?? '—',
                $minQ ?? '—',
                $cost,
            ));

            // Re-run the existing 7a tag re-suggest (plan.md §4.1 step 4 / §1.6):
            // every backfill target has convenio_id = NULL, so OCR alone does
            // not resolve scope — a facet proposal against the now-real text is
            // what gives a human something to actually verify. Only meaningful
            // while still under_review (the same guard ProposeDocumentTags::
            // handle() applies itself). This command NEVER calls confirm().
            if ($ocrd > 0 && $document->refresh()->tagging_status === 'under_review') {
                ProposeDocumentTags::dispatch($document->id);
            }
        }

        $this->newLine();
        $this->info(sprintf(
            'OCR backfill complete: %d documents processed, total cost ≈$%.4f (%d document(s) had at least one page error).',
            $documents->count(),
            $totalCost,
            $failedDocs,
        ));
        $this->line('Every document is left under_review — a human must verify the OCR text (and the tag proposal, once queued) before chunks:embed can select it.');

        if ($totalSkippedNoKey > 0) {
            $this->warn("{$totalSkippedNoKey} page(s) were skipped (not attempted, not failed) because no answer-model API key is configured — set one via POST /admin/answer-model, then re-run this command; it is idempotent and will pick up exactly the still-ocr_pending pages.");
        }

        return $failedDocs > 0 ? self::FAILURE : self::SUCCESS;
    }
}
