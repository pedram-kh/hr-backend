<?php

namespace App\Services;

use App\Models\Document;
use App\Models\DocumentPage;
use App\Models\DocumentReviewTask;
use App\Models\DocumentType;
use App\Models\TagEvent;
use App\Support\DocumentTagger;
use App\Support\FilenameParser;
use App\Support\VocabularyResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Orchestrates ingestion of one PDF (all DB writes live here — hr-ai never
 * writes the DB):
 *   1. hash the bytes (sha256 — primary idempotency key)
 *   2. store the original to S3
 *   3. call hr-ai /extract → per-page text + page-image keys
 *   4. parse the filename + tag against the registry (conflict detection)
 *   5. write documents + document_pages, tag_events provenance, and any
 *      document_review_tasks (with ADR-0011 reason + raw unmatched value)
 *
 * Idempotent: re-uploading the same bytes (or same filename+convenio) updates
 * the existing document instead of duplicating it.
 */
class DocumentIngestor
{
    public function __construct(private ExtractionClient $extractor) {}

    /**
     * @return array<string,mixed> per-file outcome for the batch response
     */
    public function ingest(
        string $tmpPath,
        string $sourceFilename,
        ?string $folderLabel,
        ?string $relativePath,
        ?int $adminId,
        VocabularyResolver $vocab,
    ): array {
        $bytes = (string) file_get_contents($tmpPath);
        $hash = hash('sha256', $bytes);

        $ext = strtolower(pathinfo($sourceFilename, PATHINFO_EXTENSION));
        // ADR-0014 (xlsx-first): any .xlsx ingested this sprint is a salary
        // document. PDFs follow the Sprint-1 prose path.
        $isXlsx = $ext === 'xlsx';

        $parsed = (new FilenameParser)->parse($sourceFilename, $folderLabel, $relativePath);
        $parsed['source_filename'] = $sourceFilename;
        $tag = (new DocumentTagger($vocab))->tag($parsed);

        if ($isXlsx) {
            // Force the salary_tables type (the filename may say "Tabla" not
            // "Tablas"). Convenio/validity still come from the parser; a
            // numero-less name lands under_review for deliberate admin convenio
            // assignment (ADR-0014, catch 4) — that is the intended path.
            $salaryType = DocumentType::where('code', 'salary_tables')->first();
            if ($salaryType !== null) {
                $tag['document_type_id'] = $salaryType->id;
                foreach ($tag['facets'] as &$facet) {
                    if (($facet['facet'] ?? null) === 'document_type') {
                        $facet['new_value'] = 'salary_tables';
                    }
                }
                unset($facet);
            }
        }

        // Idempotency: primary = content hash; fallback = filename + convenio.
        $existing = Document::where('content_hash', $hash)->first()
            ?? Document::where('source_filename', $sourceFilename)
                ->where('convenio_id', $tag['convenio_id'])
                ->first();

        $uuid = $existing?->uuid ?? (string) Str::uuid();
        $storageKey = "documents/{$uuid}/original.{$ext}";

        $contentType = $isXlsx
            ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
            : 'application/pdf';
        Storage::disk('s3')->put($storageKey, $bytes, ['ContentType' => $contentType]);

        // Salary .xlsx have no prose/page-image citation surface: skip /extract,
        // write no document_pages. Row extraction is a separate, deliberate step
        // (salary:import), after any convenio assignment.
        $pages = [];
        if (! $isXlsx) {
            $extract = $this->extractor->extract($storageKey, $uuid);
            $pages = $extract['pages'] ?? [];
        }
        $emptyText = ! $isXlsx && $pages !== [] && collect($pages)->every(
            fn ($p) => trim((string) ($p['text'] ?? '')) === ''
        );

        $document = DB::transaction(function () use (
            $existing, $uuid, $sourceFilename, $storageKey, $hash, $tag, $pages, $adminId
        ) {
            $document = $existing ?? new Document(['uuid' => $uuid]);
            $document->fill([
                'title' => $this->deriveTitle($sourceFilename),
                'source_filename' => $sourceFilename,
                'storage_path' => $storageKey,
                'content_hash' => $hash,
                'convenio_id' => $tag['convenio_id'],
                'document_type_id' => $tag['document_type_id'],
                'validity_start' => $tag['validity_start'],
                'validity_end' => $tag['validity_end'],
                'retrieval_status' => $tag['retrieval_status'],
                'authority_level' => $tag['authority_level'],
                'language' => $tag['language'],
                'tagging_status' => $tag['tagging_status'],
                'tagging_confidence' => $tag['tagging_confidence'],
                'ingested_at' => now(),
                'ingested_by' => $adminId,
            ]);
            $document->save();

            // Replace pages (re-ingest is idempotent).
            $document->pages()->delete();
            foreach ($pages as $p) {
                DocumentPage::create([
                    'document_id' => $document->id,
                    'page_number' => $p['page_number'],
                    'text' => $p['text'] ?? '',
                    'image_path' => $p['image_key'] ?? null,
                ]);
            }

            // Provenance: one filename_parse row per resolved facet.
            foreach ($tag['facets'] as $facet) {
                if (($facet['new_value'] ?? null) === null) {
                    continue;
                }
                TagEvent::create([
                    'entity_type' => 'document',
                    'entity_id' => $document->id,
                    'facet' => $facet['facet'],
                    'old_value' => null,
                    'new_value' => (string) $facet['new_value'],
                    'source' => 'filename_parse',
                    'actor_id' => null,
                    'confidence' => $facet['confidence'] ?? null,
                    'note' => 'parsed from filename',
                ]);
            }

            // On re-ingest, retire prior open review tasks before re-evaluating.
            if ($existing) {
                $document->reviewTasks()->where('status', 'open')->update([
                    'status' => 'dismissed',
                    'resolved_at' => now(),
                ]);
            }

            if ($tag['review'] !== null) {
                $review = $tag['review'];
                DocumentReviewTask::create([
                    'document_id' => $document->id,
                    'type' => $review['type'],
                    'reason' => $review['reason'],
                    'raw_unmatched_values' => $review['raw_unmatched_values'],
                    'status' => 'open',
                ]);

                // Q11: a system tag_events row on each conflicting facet.
                foreach ($review['conflict_facets'] as $cf) {
                    TagEvent::create([
                        'entity_type' => 'document',
                        'entity_id' => $document->id,
                        'facet' => $cf['facet'],
                        'old_value' => null,
                        'new_value' => null,
                        'source' => 'system',
                        'actor_id' => null,
                        'confidence' => null,
                        'note' => 'conflict: '.$cf['note'],
                    ]);
                }
                // For unresolved routing, record the unmatched raw value(s) too.
                if ($review['reason'] === 'unresolved') {
                    foreach ($review['raw_unmatched_values'] as $rv) {
                        TagEvent::create([
                            'entity_type' => 'document',
                            'entity_id' => $document->id,
                            'facet' => $rv['facet'],
                            'old_value' => null,
                            'new_value' => null,
                            'source' => 'system',
                            'actor_id' => null,
                            'confidence' => null,
                            'note' => 'unresolved value: '.$rv['value'],
                        ]);
                    }
                }
            }

            return $document;
        });

        return [
            'document_uuid' => $document->uuid,
            'title' => $document->title,
            'source_filename' => $sourceFilename,
            'tagging_status' => $document->tagging_status,
            'retrieval_status' => $document->retrieval_status,
            'review_reason' => $tag['review']['reason'] ?? null,
            'empty_text' => $emptyText,
            'created' => $existing === null,
            'page_count' => count($pages),
        ];
    }

    private function deriveTitle(string $sourceFilename): string
    {
        $base = preg_replace('/\.[A-Za-z0-9]+$/', '', $sourceFilename) ?? $sourceFilename;

        return trim((string) preg_replace('/[_]+/', ' ', $base));
    }
}
