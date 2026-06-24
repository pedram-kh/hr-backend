<?php

namespace App\Services;

use App\Models\Document;
use App\Models\DocumentPage;
use App\Support\RulingPdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Makes a published `internal_hr_ruling` retrievable through the EXISTING
 * ingestion path (Sprint 4, Q-A "A1"): render the agent's resolution text to a
 * clean PDF in S3, run hr-ai `/extract` (pages, the citation surface) and
 * `/embed` (the same call `chunks:embed` makes — hr-ai writes `document_chunks`
 * with the scope hr-backend resolves). hr-ai is NOT modified.
 *
 * The 2b answer loop is untouched: the ruling's chunks carry
 * `authority_level = internal_hr_ruling` and flow through the frozen
 * retrieve/synthesise/ground path like any prose doc. The no-override rule is
 * enforced at PUBLISH (EscalationService), not here.
 */
class RulingPublisher
{
    public function __construct(private readonly ExtractionClient $ai) {}

    /**
     * Render → store → extract pages → embed. Returns
     * { chunks_written, page_count, round_trip } where `round_trip` is the
     * lossless-verification result (chunk text vs the typed resolution text).
     *
     * @return array<string,mixed>
     */
    public function publish(Document $document, string $resolutionText): array
    {
        $pdf = RulingPdf::render($resolutionText);
        $storageKey = "documents/{$document->uuid}/original.pdf";
        Storage::disk('s3')->put($storageKey, $pdf, ['ContentType' => 'application/pdf']);

        $document->forceFill([
            'storage_path' => $storageKey,
            'content_hash' => hash('sha256', $pdf),
        ])->save();

        // /extract → per-page text + page images (the citation surface), written
        // to document_pages exactly like a normal ingestion (DocumentIngestor).
        $extract = $this->ai->extract($storageKey, $document->uuid);
        $pages = $extract['pages'] ?? [];
        DB::transaction(function () use ($document, $pages) {
            $document->pages()->delete();
            foreach ($pages as $p) {
                DocumentPage::create([
                    'document_id' => $document->id,
                    'page_number' => $p['page_number'],
                    'text' => $p['text'] ?? '',
                    'image_path' => $p['image_key'] ?? null,
                ]);
            }
        });

        // /embed → hr-ai writes document_chunks with the resolved scope (ADR-0007).
        $result = $this->ai->embed($document->id, $document->uuid, $storageKey, $this->resolveScope($document));
        $chunksWritten = (int) ($result['chunks_written'] ?? 0);

        return [
            'chunks_written' => $chunksWritten,
            'page_count' => count($pages),
            'round_trip' => $this->verifyRoundTrip($document->id, $resolutionText),
        ];
    }

    /**
     * Verify the embedded chunk text is a LOSSLESS round-trip of the typed
     * resolution (Q-A required check). Compares whitespace-normalised text — the
     * extractor legitimately collapses runs of whitespace, but must NOT alter
     * words, drop characters, or de-hyphenate. A mismatch is the signal to fall
     * back to A2 (an hr-ai inline-text branch) rather than ship a mangled ruling.
     *
     * @return array<string,mixed>
     */
    public function verifyRoundTrip(int $documentId, string $resolutionText): array
    {
        $chunks = DB::table('document_chunks')
            ->where('document_id', $documentId)
            ->orderBy('chunk_index')
            ->pluck('content')
            ->all();

        $embedded = $this->normalize(implode(' ', $chunks));
        $expected = $this->normalize($resolutionText);

        $lossless = $embedded === $expected;

        return [
            'lossless' => $lossless,
            'expected_normalized' => $expected,
            'embedded_normalized' => $embedded,
            'expected_len' => mb_strlen($expected),
            'embedded_len' => mb_strlen($embedded),
            'chunk_count' => count($chunks),
        ];
    }

    /** Collapse all whitespace to single spaces and trim (the legitimate, lossless transform). */
    private function normalize(string $s): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $s));
    }

    /**
     * Resolve the denormalized scope for the ruling (ADR-0007) — identical to
     * `ChunksEmbed::resolveScope`: territory/sector ride the convenio (the
     * asker's, inherited at publish); validity/status/authority from the doc.
     *
     * @return array<string,mixed>
     */
    private function resolveScope(Document $document): array
    {
        $document->loadMissing('convenio');

        return [
            'convenio_id' => $document->convenio_id,
            'territory_id' => $document->convenio?->territory_id,
            'sector_id' => $document->convenio?->sector_id,
            'validity_start' => $document->validity_start?->toDateString(),
            'validity_end' => $document->validity_end?->toDateString(),
            'retrieval_status' => $document->retrieval_status,
            'authority_level' => $document->authority_level,
        ];
    }
}
