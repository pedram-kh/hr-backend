<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Services\ExtractionClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Selects the in-scope prose documents, resolves each document's scope, and
 * triggers hr-ai /embed (which re-extracts column-aware, de-spaces, article-
 * chunks, embeds BGE-M3/1024, and WRITES document_chunks directly — ADR-0013).
 *
 * hr-backend owns scope resolution (ADR-0007): the denormalized scope columns
 * on document_chunks are populated from the system of record and PASSED to
 * hr-ai. hr-ai never derives scope.
 *
 * Selection (plan §4): document_type ∈ {convenio_text, national_law,
 * partial_agreement}; retrieval_status ∈ {active, historical} (excludes draft);
 * tagging_status ≠ under_review (ADR-0013 — conflicted/unresolved scope is not
 * yet trustworthy). Salary-type documents are NEVER chunked.
 */
class ChunksEmbed extends Command
{
    protected $signature = 'chunks:embed {--document= : embed a single document by uuid} {--dry-run : list the selection only}';

    protected $description = 'Chunk + embed in-scope prose documents into document_chunks (hr-ai writes; hr-backend passes scope).';

    // Sprint 4: `internal_hr_ruling` joins the prose-embed set so a published
    // resolution-article becomes retrievable through the SAME path (Q-A "A1").
    // It is prose (a generated PDF, RulingPublisher) and, like a convenio, must
    // be active + tagging_status ≠ under_review to be selected.
    private const IN_SCOPE_TYPES = ['convenio_text', 'national_law', 'partial_agreement', 'internal_hr_ruling'];

    public function handle(ExtractionClient $client): int
    {
        $query = Document::query()
            ->with(['documentType', 'convenio'])
            ->whereHas('documentType', fn ($q) => $q->whereIn('code', self::IN_SCOPE_TYPES))
            ->whereIn('retrieval_status', ['active', 'historical'])
            ->where('tagging_status', '!=', 'under_review');

        if ($uuid = $this->option('document')) {
            $query->where('uuid', $uuid);
        }

        $documents = $query->orderBy('id')->get();
        $this->info("In-scope documents selected for embedding: {$documents->count()}");

        if ($this->option('dry-run')) {
            foreach ($documents as $d) {
                $this->line("  [{$d->id}] {$d->documentType?->code} / {$d->retrieval_status} / {$d->tagging_status} — {$d->source_filename}");
            }

            return self::SUCCESS;
        }

        $totalChunks = 0;
        $failed = 0;
        $suspicious = [];
        foreach ($documents as $d) {
            $scope = $this->resolveScope($d);
            try {
                $result = $client->embed($d->id, $d->uuid, $d->storage_path, $scope);
                $written = (int) ($result['chunks_written'] ?? 0);
                $totalChunks += $written;
                $streams = $result['language_streams'] ?? ['es' => 0, 'eu' => 0];
                $stats = $result['stats'] ?? [];
                $furn = (int) ($stats['furniture_blocks_stripped'] ?? 0);
                $notSplit = $stats['pages_not_cleanly_split'] ?? [];
                $notSplitStr = $notSplit === [] ? 'none' : implode(',', $notSplit);
                $this->line("  [{$d->id}] {$d->source_filename}: {$written} chunks (es={$streams['es']} eu={$streams['eu']}); furniture_stripped={$furn}; pages_not_cleanly_split={$notSplitStr}");

                if ($reason = $this->overStripReason($written, $stats)) {
                    $suspicious[] = ['doc' => $d, 'reason' => $reason];
                    Log::warning('chunks:embed suspicious document (possible over-strip / under-chunking)', [
                        'document_id' => $d->id, 'uuid' => $d->uuid, 'file' => $d->source_filename,
                        'reason' => $reason, 'chunks' => $written, 'stats' => $stats,
                    ]);
                }
            } catch (\Throwable $e) {
                $failed++;
                $this->error("  [{$d->id}] {$d->source_filename}: ".$e->getMessage());
            }
        }

        $this->newLine();
        $this->info("Embed complete: {$totalChunks} chunks written across {$documents->count()} documents ({$failed} failed).");

        // Audit-first (Correction-01, change 3): surface suspicious docs by
        // construction so over-stripping can never silently recur — the same way
        // salary:import surfaces coverage gaps.
        if ($suspicious !== []) {
            $this->newLine();
            $this->warn('⚠ SUSPICIOUS DOCUMENTS — review before trusting their chunks ('.count($suspicious).'):');
            foreach ($suspicious as $s) {
                $this->line("  [{$s['doc']->id}] {$s['doc']->source_filename}: {$s['reason']}");
            }
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Per-document over-strip / under-chunking sanity check (Correction-01,
     * change 3). Returns a human reason when a doc looks suspicious, else null.
     *
     * - over-strip: more blocks stripped as furniture than kept for columns.
     * - under-chunking: a non-scanned doc (it HAD extractable text blocks) that
     *   yields implausibly few chunks for its length (< 1 chunk / 3 pages).
     *
     * A genuinely scanned/image PDF (few/no text blocks) is NOT flagged here —
     * that is a separate OCR concern, out of scope this sprint.
     *
     * @param  array<string,mixed>  $stats
     */
    private function overStripReason(int $written, array $stats): ?string
    {
        $pageCount = (int) ($stats['page_count'] ?? 0);
        $kept = (int) ($stats['column_blocks_kept'] ?? 0);
        $stripped = (int) ($stats['furniture_blocks_stripped'] ?? 0);
        $blocksTotal = (int) ($stats['blocks_total'] ?? 0);

        if ($stripped > $kept && $stripped > 0) {
            return "over-strip — {$stripped} blocks stripped as furniture vs {$kept} kept";
        }

        // Only flag low yield when the PDF actually had text (not a scan).
        if ($pageCount >= 6 && $blocksTotal >= $pageCount && ($written * 3) < $pageCount) {
            return "under-chunking — only {$written} chunks for {$pageCount} pages ({$blocksTotal} text blocks present)";
        }

        return null;
    }

    /**
     * Resolve the denormalized scope for a document (ADR-0007). Territory/sector
     * ride the convenio (Sprint-1 decision); national-law docs are universal
     * (convenio/territory/sector NULL, authority_level = national_law).
     *
     * @return array<string,mixed>
     */
    private function resolveScope(Document $d): array
    {
        return [
            'convenio_id' => $d->convenio_id,
            'territory_id' => $d->convenio?->territory_id,
            'sector_id' => $d->convenio?->sector_id,
            'validity_start' => $d->validity_start?->toDateString(),
            'validity_end' => $d->validity_end?->toDateString(),
            'retrieval_status' => $d->retrieval_status,
            'authority_level' => $d->authority_level,
        ];
    }
}
