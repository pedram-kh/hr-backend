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
     * @param  bool  $asReference  Sprint 7b-1 (ADR-0021): ingest a NON-salary
     *   .docx/.xlsx as a `reference_source` document — the deliberate routing tag
     *   (Invariant 2). Its content is read via hr-ai /read-structured and stored
     *   as display `document_pages`; it is NEVER embedded (reference_source ∉
     *   ChunksEmbed::IN_SCOPE_TYPES) and NEVER touches the salary path. The
     *   reference FACTS are created by hand from this source (the manual path).
     * @return array<string,mixed> per-file outcome for the batch response
     */
    public function ingest(
        string $tmpPath,
        string $sourceFilename,
        ?string $folderLabel,
        ?string $relativePath,
        ?int $adminId,
        VocabularyResolver $vocab,
        bool $asReference = false,
    ): array {
        $bytes = (string) file_get_contents($tmpPath);
        $hash = hash('sha256', $bytes);

        $ext = strtolower(pathinfo($sourceFilename, PATHINFO_EXTENSION));
        // ADR-0014 (xlsx-first): any .xlsx ingested on the default path is a
        // salary document. PDFs follow the Sprint-1 prose path. A reference
        // source (Sprint 7b-1) overrides BOTH — it is non-salary, routed by tag.
        $isXlsx = $ext === 'xlsx' && ! $asReference;

        $parsed = (new FilenameParser)->parse($sourceFilename, $folderLabel, $relativePath);
        $parsed['source_filename'] = $sourceFilename;
        $tag = (new DocumentTagger($vocab))->tag($parsed);

        if ($asReference) {
            // Force the reference_source type (Invariant 2: routing rides the
            // document_type tag, never content). Convenio/validity still come
            // from the parser; a multi-scope reference file usually lands
            // under_review with no single convenio — correct (its scope lives on
            // the per-fact rows, not the source). It is NEVER on the salary path
            // and NEVER embedded.
            $refType = DocumentType::where('code', 'reference_source')->first();
            if ($refType !== null) {
                $tag['document_type_id'] = $refType->id;
                foreach ($tag['facets'] as &$facet) {
                    if (($facet['facet'] ?? null) === 'document_type') {
                        $facet['new_value'] = 'reference_source';
                    }
                }
                unset($facet);
            }
        }

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

        $contentType = match (true) {
            $asReference && $ext === 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            $asReference && $ext === 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $isXlsx => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            default => 'application/pdf',
        };
        Storage::disk('s3')->put($storageKey, $bytes, ['ContentType' => $contentType]);

        // Page surface by path:
        //  - reference_source (7b-1): hr-ai /read-structured returns per-section/
        //    per-sheet content; stored as display document_pages (one row each).
        //    NEVER embedded (reference_source ∉ IN_SCOPE_TYPES, ADR-0006).
        //  - salary .xlsx: no prose/page-image surface — skip (salary:import).
        //  - PDF prose: /extract per-page text + images (the Sprint-1 path).
        $pages = [];
        if ($asReference) {
            $read = $this->extractor->readStructured($storageKey, $uuid, $ext);
            foreach ($read['pages'] ?? [] as $p) {
                $label = trim((string) ($p['label'] ?? ''));
                $text = (string) ($p['text'] ?? '');
                // Prefix the section/sheet label so the reader view + manual
                // source_locator entry are self-describing (xlsx sheet name, docx
                // section heading). document_pages has no label column.
                $pages[] = [
                    'page_number' => $p['page_number'] ?? 1,
                    'text' => $label !== '' ? "[{$label}]\n{$text}" : $text,
                    'image_key' => null,
                ];
            }
        } elseif (! $isXlsx) {
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

        // Sprint 7a (auto-propose-on-ingest, §7.2): an `unresolved` document is
        // LLM-eligible (ADR-0011). Dispatch the AI tagging proposal as a QUEUED
        // job AFTER the transaction commits — it must not block or fail ingest.
        // The doc is already safely under_review (the embedding gate keeps it
        // unretrievable); the queue self-populates and a human verifies. A
        // `conflict` is human-adjudicated (the AI may suggest but never on the
        // conflict path here), so only `unresolved` auto-triggers.
        //
        // A reference_source is deliberately EXCLUDED: it is inherently
        // multi-scope (no single convenio), so the 7a document-level tagger would
        // mis-propose one convenio. Its facts are created by hand (7b-1); the AI
        // fact-segmentation is 7b-2 — not pre-wired here.
        if (! $asReference && ($tag['review']['reason'] ?? null) === 'unresolved') {
            \App\Jobs\ProposeDocumentTags::dispatch($document->id);
        }

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
            'as_reference' => $asReference,
        ];
    }

    private function deriveTitle(string $sourceFilename): string
    {
        $base = preg_replace('/\.[A-Za-z0-9]+$/', '', $sourceFilename) ?? $sourceFilename;

        return trim((string) preg_replace('/[_]+/', ' ', $base));
    }
}
