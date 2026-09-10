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
     * @param  bool  $ocr  Sprint 7e (ADR-0026, review.md §2.1/§2.7): opt-in OCR
     *   fallback for text-less PDF pages. Default off (unchanged Sprint-1
     *   behavior) — forwarded verbatim to hr-ai's `/extract`; hr-ai itself never
     *   calls the OCR model here, it only marks a page `ocr_pending`.
     * @param  int  $ocrPageCap  Per-document page cap (review.md §2.7) — bounds
     *   worst-case cost/latency for one pathological upload.
     * @param  bool  $confirmScopeChange  Sprint 7g Item 3 (F-1, ADR-0029-adjacent):
     *   a checksum (content_hash) match is an IDENTITY match — it must never
     *   silently re-type/re-scope the existing document just because the file
     *   arrived under a different name or a different `--as-reference` flag.
     *   Mirrors the exact manual-edit gate ({@see \App\Http\Controllers\Admin\DocumentController::reassignFacet()}):
     *   default false reports the collision and changes nothing; true applies
     *   it and records an `admin_manual` provenance event (never `filename_parse`
     *   for THIS write, since a human explicitly asked for it).
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
        bool $ocr = false,
        int $ocrPageCap = 60,
        bool $confirmScopeChange = false,
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
        $existingByHash = Document::where('content_hash', $hash)->first();
        $existing = $existingByHash
            ?? Document::where('source_filename', $sourceFilename)
                ->where('convenio_id', $tag['convenio_id'])
                ->first();

        // Sprint 7g Item 3 (F-1): a CHECKSUM match is the strongest possible
        // identity signal — the bytes are IDENTICAL to an already-ingested
        // document. It must never silently re-type/re-scope that document just
        // because this call's filename/`--as-reference` flag parsed to a
        // different tag than last time (e.g. the same salary .xlsx re-ingested
        // with `--as-reference`, which would otherwise flip it to
        // `reference_source` with no gate at all — never embedded, never
        // salary-queryable again, with no audit trail explaining why). Checked
        // ONLY on the hash match (not the filename+convenio fallback, which is
        // a deliberate "same slot, different bytes, same identity" update path
        // predating this sprint and unaffected by it).
        $scopeChanges = [];
        if ($existingByHash !== null) {
            $scopeChanges = $this->detectScopeChanges($existingByHash, $tag);
            if ($scopeChanges !== [] && ! $confirmScopeChange) {
                $typeCode = $existingByHash->documentType?->code ?? 'sin tipo';

                return [
                    'document_uuid' => $existingByHash->uuid,
                    'source_filename' => $sourceFilename,
                    'confirm_scope_change_required' => true,
                    'scope_changes' => $scopeChanges,
                    'message' => "Ya existe como documento {$existingByHash->id} (tipo {$typeCode}) — mismo contenido (checksum), pero este archivo se etiquetaría de forma distinta. No se ha cambiado nada. Reenvía con confirm_scope_change=true (o --retype en CLI) para aplicar el retipo.",
                    'created' => false,
                    'unchanged' => true,
                ];
            }
        }

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
            // Sprint 7e (ADR-0026): $ocr is only ever meaningful on this PDF-prose
            // path — a reference_source uses /read-structured (no OCR fallback,
            // out of scope), and a salary .xlsx has no page-image surface at all.
            $extract = $this->extractor->extract($storageKey, $uuid, $ocr, $ocrPageCap);
            $pages = $extract['pages'] ?? [];
        }
        $emptyText = ! $isXlsx && $pages !== [] && collect($pages)->every(
            fn ($p) => trim((string) ($p['text'] ?? '')) === ''
        );

        $document = DB::transaction(function () use (
            $existing, $uuid, $sourceFilename, $storageKey, $hash, $tag, $pages, $adminId,
            $scopeChanges, $confirmScopeChange,
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

            // Sprint 7g Item 3 (F-1): the explicit confirm path for a checksum-
            // match retype. One `admin_manual` provenance row per changed facet
            // — a human (or a CLI operator who typed `--retype`) explicitly
            // asked for this, so it is never `filename_parse` even though the
            // NEW value did come from parsing the new filename/flag.
            if ($confirmScopeChange && $scopeChanges !== []) {
                foreach ($scopeChanges as $change) {
                    TagEvent::create([
                        'entity_type' => 'document',
                        'entity_id' => $document->id,
                        'facet' => $change['facet'],
                        'old_value' => $change['old_display'],
                        'new_value' => $change['new_display'],
                        'source' => 'admin_manual',
                        'actor_id' => $adminId,
                        'confidence' => null,
                        'note' => 'checksum-matched re-ingest, explicitly confirmed retype (confirm_scope_change/--retype)',
                    ]);
                }
            }

            // Replace pages (re-ingest is idempotent).
            $document->pages()->delete();
            foreach ($pages as $p) {
                DocumentPage::create([
                    'document_id' => $document->id,
                    'page_number' => $p['page_number'],
                    'text' => $p['text'] ?? '',
                    'image_path' => $p['image_key'] ?? null,
                    // Sprint 7e (ADR-0026, review.md §2.1): verbatim from hr-ai's
                    // /extract per-page field. Absent (reference/xlsx paths, or an
                    // older hr-ai response) defaults to 'text_layer' — the DB
                    // column's own default, and the correct value for every path
                    // that never asked for OCR.
                    'extraction_source' => $p['extraction_source'] ?? 'text_layer',
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
        // A reference_source is deliberately EXCLUDED from the 7a document-level
        // tagger: it is inherently multi-scope (no single convenio), so that
        // tagger would mis-propose one convenio.
        if (! $asReference && ($tag['review']['reason'] ?? null) === 'unresolved') {
            \App\Jobs\ProposeDocumentTags::dispatch($document->id);
        }

        // Sprint 7e (ADR-0026, review.md §2.1): mirrors the exact
        // dispatch-after-commit pattern above. Fired once per document, only
        // when at least one page came back `ocr_pending` from hr-ai's /extract
        // (i.e. only ever when $ocr was true and a text-less page existed within
        // the cap). OcrDocumentPages itself does no OCR — it fans out one
        // OcrPage job per pending page and returns immediately, so ingest never
        // blocks on it.
        if (collect($pages)->contains(fn ($p) => ($p['extraction_source'] ?? null) === 'ocr_pending')) {
            \App\Jobs\OcrDocumentPages::dispatch($document->id);
        }

        // Sprint 7d (ADR-0024, §8.5): an official convenio arriving ACTIVE in a
        // scope may have overtaken rulings published there while it was silent.
        // Queued after commit, FLAG-ONLY (a `conflict` review task on the ruling —
        // never a demotion, never a retrieval touch), and it never rethrows, so a
        // comparison failure cannot fail ingest.
        if ($document->authority_level === 'official_convenio' && $document->retrieval_status === 'active') {
            \App\Jobs\RecheckRulingsForConvenio::dispatch($document->id);
        }

        // Sprint 7b-2 (ADR-0022): a reference_source IS auto-segmented — but by
        // the per-FACT segmentation agent (not the document tagger). Queued AFTER
        // commit so it never blocks/fails ingest; the proposed facts are inert
        // (ai_agent/needs_review, not answerable) until a human verifies. The
        // routing invariant holds: only a reference_source reaches this path, and
        // the segmenter never writes a salary row.
        if ($asReference) {
            \App\Jobs\SegmentReferenceSource::dispatch($document->id);
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

    /**
     * Sprint 7g Item 3 (F-1) — the four scope-affecting facets a checksum-
     * matched re-ingest must NEVER silently overwrite: which employees receive
     * this document (`convenio_id`), what kind of source it is and therefore
     * how it's used (`document_type_id` — salary SQL vs prose vs reference_source,
     * embedded vs not), and its eligibility window (`validity_start`/`_end`).
     * `tagging_status`/`tagging_confidence`/`retrieval_status`/`authority_level`
     * are NOT gated here — they are DERIVED from the same facets already being
     * checked, or (retrieval_status) already re-computed by the tagger from
     * validity on every re-ingest regardless, matching Sprint-1 idempotent
     * re-ingest behavior for content that didn't change identity.
     *
     * @param  array<string,mixed>  $tag
     * @return list<array{facet:string, old_display:?string, new_display:?string}>
     */
    private function detectScopeChanges(Document $existing, array $tag): array
    {
        $changes = [];

        if ((int) $existing->document_type_id !== (int) ($tag['document_type_id'] ?? 0)) {
            $changes[] = [
                'facet' => 'document_type',
                'old_display' => $existing->documentType?->code,
                'new_display' => DocumentType::find($tag['document_type_id'])?->code,
            ];
        }

        $existingConvenioId = $existing->convenio_id;
        $newConvenioId = $tag['convenio_id'] ?? null;
        if ($existingConvenioId !== $newConvenioId) {
            $changes[] = [
                'facet' => 'convenio',
                'old_display' => $existingConvenioId ? $existing->convenio?->numero : null,
                'new_display' => $newConvenioId ? \App\Models\Convenio::find($newConvenioId)?->numero : null,
            ];
        }

        $oldStart = $existing->validity_start?->toDateString();
        $newStart = $tag['validity_start'] ?? null;
        if ($oldStart !== $newStart) {
            $changes[] = ['facet' => 'validity_start', 'old_display' => $oldStart, 'new_display' => $newStart];
        }

        $oldEnd = $existing->validity_end?->toDateString();
        $newEnd = $tag['validity_end'] ?? null;
        if ($oldEnd !== $newEnd) {
            $changes[] = ['facet' => 'validity_end', 'old_display' => $oldEnd, 'new_display' => $newEnd];
        }

        return $changes;
    }

    private function deriveTitle(string $sourceFilename): string
    {
        $base = preg_replace('/\.[A-Za-z0-9]+$/', '', $sourceFilename) ?? $sourceFilename;

        return trim((string) preg_replace('/[_]+/', ' ', $base));
    }
}
