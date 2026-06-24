<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Convenio;
use App\Models\Document;
use App\Models\DocumentTopic;
use App\Models\DocumentType;
use App\Models\TagEvent;
use App\Models\Topic;
use App\Services\DocumentIngestor;
use App\Support\KnowledgeMap;
use App\Support\VocabularyResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class DocumentController extends Controller
{
    /** Facets an admin can re-assign on a document (FKs stored on documents). */
    private const REASSIGNABLE = ['convenio', 'document_type'];

    /**
     * Knowledge → Documents table. Filterable by facet + tagging_status; flags
     * conflicts and empty-text scans.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Document::query()
            ->with(['convenio.territory', 'convenio.sector', 'documentType'])
            ->select('documents.*')
            ->selectRaw('(select count(*) from document_pages dp where dp.document_id = documents.id) as pages_total')
            ->selectRaw("(select count(*) from document_pages dp where dp.document_id = documents.id and length(btrim(coalesce(dp.text, ''))) > 0) as pages_with_text")
            ->selectRaw("exists(select 1 from document_review_tasks t where t.document_id = documents.id and t.status = 'open' and t.reason = 'conflict') as has_open_conflict")
            ->selectRaw("exists(select 1 from document_review_tasks t where t.document_id = documents.id and t.status = 'open') as has_open_review");

        if ($request->filled('tagging_status')) {
            $query->where('tagging_status', $request->string('tagging_status'));
        }
        if ($request->filled('document_type_id')) {
            $query->where('document_type_id', $request->integer('document_type_id'));
        }
        if ($request->filled('convenio_id')) {
            $query->where('convenio_id', $request->integer('convenio_id'));
        }
        if ($request->filled('territory_id')) {
            $query->whereHas('convenio', fn ($q) => $q->where('territory_id', $request->integer('territory_id')));
        }
        if ($request->filled('sector_id')) {
            $query->whereHas('convenio', fn ($q) => $q->where('sector_id', $request->integer('sector_id')));
        }
        if ($request->boolean('conflicts_only')) {
            $query->whereHas('reviewTasks', fn ($q) => $q->where('status', 'open')->where('reason', 'conflict'));
        }

        $documents = $query->orderByDesc('id')->paginate(50);

        $documents->getCollection()->transform(fn (Document $d) => $this->listRow($d));

        return response()->json($documents);
    }

    public function show(string $uuid): JsonResponse
    {
        $document = Document::with([
            'convenio.territory', 'convenio.sector', 'documentType', 'pages', 'reviewTasks',
            'predecessor:id,uuid,title,validity_start,validity_end,retrieval_status',
            'successors:id,uuid,title,validity_start,validity_end,retrieval_status,predecessor_document_id',
            'topics',
        ])
            ->where('uuid', $uuid)
            ->firstOrFail();

        $events = TagEvent::where('entity_type', 'document')
            ->where('entity_id', $document->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['facet', 'old_value', 'new_value', 'source', 'actor_id', 'confidence', 'note', 'created_at']);

        return response()->json([
            'uuid' => $document->uuid,
            'title' => $document->title,
            'source_filename' => $document->source_filename,
            'language' => $document->language,
            'validity_start' => $document->validity_start?->toDateString(),
            'validity_end' => $document->validity_end?->toDateString(),
            'retrieval_status' => $document->retrieval_status,
            'authority_level' => $document->authority_level,
            'tagging_status' => $document->tagging_status,
            'tagging_confidence' => $document->tagging_confidence,
            'tags' => $this->tags($document),
            'topics' => $document->topics->map(fn ($t) => [
                'id' => $t->id,
                'name' => $t->name,
                'source' => $t->pivot->source,
                'confidence' => $t->pivot->confidence !== null ? (float) $t->pivot->confidence : null,
                'verified_by' => $t->pivot->verified_by,
                'verified_at' => $t->pivot->verified_at,
            ]),
            'lineage' => [
                'predecessor' => $document->predecessor ? $this->lineageRef($document->predecessor) : null,
                'successors' => $document->successors->map(fn ($d) => $this->lineageRef($d))->values(),
            ],
            'chunk_health' => KnowledgeMap::chunkHealth($document->id),
            // Scope-rides-on-convenio limitation (data-model): a non-national doc
            // with no convenio cannot carry scope — flagged, never silently mis-scoped.
            'is_unscoped' => $document->convenio_id === null && $document->authority_level !== 'national_law',
            'pages' => $document->pages->map(fn ($p) => [
                'page_number' => $p->page_number,
                'text' => $p->text,
                'has_text' => trim((string) $p->text) !== '',
                'image_path' => $p->image_path,
            ]),
            'empty_text' => $document->pages->isNotEmpty() && $document->pages->every(fn ($p) => trim((string) $p->text) === ''),
            'review_tasks' => $document->reviewTasks->map(fn ($t) => [
                'type' => $t->type,
                'reason' => $t->reason,
                'status' => $t->status,
                'raw_unmatched_values' => $t->raw_unmatched_values,
            ]),
            'provenance' => $events,
        ]);
    }

    /** @return array<string,mixed> a compact reference to a lineage-linked document. */
    private function lineageRef(Document $d): array
    {
        return [
            'uuid' => $d->uuid,
            'title' => $d->title,
            'validity_start' => $d->validity_start?->toDateString(),
            'validity_end' => $d->validity_end?->toDateString(),
            'retrieval_status' => $d->retrieval_status,
        ];
    }

    /**
     * Folder/batch upload (PDF only). Preserves folder grouping via the
     * per-file relative path; non-PDFs are skipped (reported, not ingested).
     */
    public function upload(Request $request, DocumentIngestor $ingestor): JsonResponse
    {
        $request->validate([
            'files' => ['required', 'array', 'min:1'],
            'files.*' => ['file'],
        ]);

        $relativePaths = (array) $request->input('relative_paths', []);
        $adminId = $request->user()->id;
        $vocab = new VocabularyResolver; // one resolver per batch (caches vocab)

        $results = [];
        foreach ($request->file('files') as $i => $file) {
            $original = $file->getClientOriginalName();
            $relativePath = $relativePaths[$i] ?? $original;
            $folderLabel = $this->topFolder($relativePath);

            // Sprint 2a accepts PDFs (prose) and salary .xlsx (ADR-0014 xlsx-first).
            // Other formats (.doc/.docx prose, .xls) remain out of scope.
            $ext = strtolower((string) $file->getClientOriginalExtension());
            $isPdf = $ext === 'pdf' || $file->getMimeType() === 'application/pdf';
            $isXlsx = $ext === 'xlsx'
                || $file->getMimeType() === 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

            if (! $isPdf && ! $isXlsx) {
                $results[] = [
                    'source_filename' => $original,
                    'skipped' => true,
                    'reason' => 'unsupported format (PDF prose + salary .xlsx only this sprint)',
                ];

                continue;
            }

            try {
                $results[] = $ingestor->ingest(
                    $file->getRealPath(),
                    $original,
                    $folderLabel,
                    $relativePath,
                    $adminId,
                    $vocab,
                );
            } catch (\Throwable $e) {
                $results[] = [
                    'source_filename' => $original,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return response()->json(['results' => $results]);
    }

    /** Confirm tags: set verified, write provenance, resolve open review tasks. */
    public function confirm(Request $request, string $uuid): JsonResponse
    {
        $document = Document::where('uuid', $uuid)->firstOrFail();
        $adminId = $request->user()->id;

        DB::transaction(function () use ($document, $adminId) {
            $document->update(['tagging_status' => 'verified']);

            TagEvent::create([
                'entity_type' => 'document',
                'entity_id' => $document->id,
                'facet' => 'document',
                'old_value' => null,
                'new_value' => 'verified',
                'source' => 'admin_manual',
                'actor_id' => $adminId,
                'confidence' => null,
                'note' => 'tags confirmed',
            ]);

            $document->reviewTasks()->where('status', 'open')->update([
                'status' => 'resolved',
                'resolved_by' => $adminId,
                'resolved_at' => now(),
            ]);
        });

        return response()->json(['status' => 'ok', 'tagging_status' => 'verified']);
    }

    /** Re-assign a single facet from controlled vocabulary; writes provenance. */
    public function reassignFacet(Request $request, string $uuid, string $facet): JsonResponse
    {
        if (! in_array($facet, self::REASSIGNABLE, true)) {
            return response()->json(['message' => "Facet '{$facet}' is not re-assignable. Allowed: ".implode(', ', self::REASSIGNABLE)], 422);
        }
        $data = $request->validate([
            'value_id' => ['required', 'integer'],
            'confirm_scope_change' => ['sometimes', 'boolean'],
        ]);
        $document = Document::where('uuid', $uuid)->firstOrFail();
        $adminId = $request->user()->id;

        // A convenio reassign changes the document's (derived) territory + sector,
        // i.e. which employees get it as an answer — scope-affecting (spec D). It
        // must be explicitly confirmed. A document_type retype is not scope-affecting.
        if ($facet === 'convenio' && ! $request->boolean('confirm_scope_change')) {
            return response()->json([
                'message' => 'This changes which employees receive this document as an answer. Re-send with confirm_scope_change=true to apply.',
                'scope_affecting' => true,
            ], 409);
        }

        [$column, $model, $display] = match ($facet) {
            'convenio' => ['convenio_id', Convenio::class, 'numero'],
            'document_type' => ['document_type_id', DocumentType::class, 'code'],
        };

        $value = $model::find($data['value_id']);
        if ($value === null) {
            return response()->json(['message' => 'Value not found in controlled vocabulary.'], 422);
        }

        $old = $document->{$column};
        $oldDisplay = $old ? optional($model::find($old))->{$display} : null;

        DB::transaction(function () use ($document, $column, $value, $facet, $display, $oldDisplay, $adminId) {
            $document->update([$column => $value->id]);

            TagEvent::create([
                'entity_type' => 'document',
                'entity_id' => $document->id,
                'facet' => $facet,
                'old_value' => $oldDisplay,
                'new_value' => (string) $value->{$display},
                'source' => 'admin_manual',
                'actor_id' => $adminId,
                'confidence' => null,
                'note' => 'facet re-assigned by admin',
            ]);
        });

        return response()->json(['status' => 'ok']);
    }

    /** Pre-signed (temporary) URL for a page image stored in S3. */
    public function pageImage(string $uuid, int $page): JsonResponse
    {
        $document = Document::where('uuid', $uuid)->firstOrFail();
        $pageRow = $document->pages()->where('page_number', $page)->firstOrFail();

        if (! $pageRow->image_path) {
            return response()->json(['message' => 'No image for this page.'], 404);
        }

        $url = Storage::disk('s3')->temporaryUrl($pageRow->image_path, now()->addMinutes(10));

        return response()->json(['url' => $url]);
    }

    /**
     * Pre-signed (temporary) URL for the ORIGINAL source file (the real-document
     * viewer, spec C). Same S3 mechanism as {@see pageImage()} — the browser
     * fetches the bytes directly from S3; they never proxy through hr-backend.
     */
    public function source(string $uuid): JsonResponse
    {
        $document = Document::where('uuid', $uuid)->firstOrFail();

        if (! $document->storage_path) {
            return response()->json(['message' => 'No source file for this document.'], 404);
        }

        $url = Storage::disk('s3')->temporaryUrl($document->storage_path, now()->addMinutes(10));
        $ext = strtolower(pathinfo((string) $document->storage_path, PATHINFO_EXTENSION));

        return response()->json([
            'url' => $url,
            'content_type' => $ext === 'pdf' ? 'application/pdf' : null,
            'filename' => $document->source_filename,
        ]);
    }

    /**
     * Bounded edit of the lifecycle facets (spec D): validity_start/_end,
     * retrieval_status, tagging_status. FK-backed facets (convenio/document_type)
     * use {@see reassignFacet()}; topics use {@see addTopic()}/{@see removeTopic()}.
     * Territory/sector are NOT editable (derived from the convenio).
     *
     * Each changed field is written to `documents` AND appended as a `human`
     * (admin_manual) tag_events row (old→new, actor_id) — append-only, never an
     * UPDATE/DELETE of history. A scope-affecting change (retrieval_status flip or
     * validity edit — both move the eligibility window) requires
     * `confirm_scope_change=true`, else 409.
     */
    public function updateLifecycle(Request $request, string $uuid): JsonResponse
    {
        $data = $request->validate([
            'validity_start' => ['sometimes', 'nullable', 'date'],
            'validity_end' => ['sometimes', 'nullable', 'date'],
            'retrieval_status' => ['sometimes', 'in:draft,active,historical'],
            'tagging_status' => ['sometimes', 'in:auto_proposed,under_review,verified'],
            'confirm_scope_change' => ['sometimes', 'boolean'],
        ]);

        $document = Document::where('uuid', $uuid)->firstOrFail();
        $adminId = $request->user()->id;

        // Determine the changed fields (only those actually present and different).
        $changes = []; // facet => [old, new, column]
        if ($request->has('retrieval_status') && $data['retrieval_status'] !== $document->retrieval_status) {
            $changes['retrieval_status'] = [$document->retrieval_status, $data['retrieval_status'], 'retrieval_status'];
        }
        if ($request->has('tagging_status') && $data['tagging_status'] !== $document->tagging_status) {
            $changes['tagging_status'] = [$document->tagging_status, $data['tagging_status'], 'tagging_status'];
        }
        $newStart = $request->has('validity_start') ? ($data['validity_start'] ?? null) : $document->validity_start?->toDateString();
        $newEnd = $request->has('validity_end') ? ($data['validity_end'] ?? null) : $document->validity_end?->toDateString();
        $validityChanged = ($request->has('validity_start') || $request->has('validity_end'))
            && ("{$newStart}..{$newEnd}" !== "{$document->validity_start?->toDateString()}..{$document->validity_end?->toDateString()}");

        if (empty($changes) && ! $validityChanged) {
            return response()->json(['status' => 'ok', 'note' => 'no change']);
        }

        // Scope-affecting: a retrieval_status flip or any validity edit moves the
        // eligibility window (who gets this as an answer). tagging_status alone does not.
        $scopeAffecting = isset($changes['retrieval_status']) || $validityChanged;
        if ($scopeAffecting && ! $request->boolean('confirm_scope_change')) {
            return response()->json([
                'message' => 'This changes which employees receive this document as an answer. Re-send with confirm_scope_change=true to apply.',
                'scope_affecting' => true,
            ], 409);
        }

        DB::transaction(function () use ($document, $changes, $validityChanged, $newStart, $newEnd, $adminId) {
            foreach ($changes as $facet => [$old, $new]) {
                $document->{$facet} = $new;
                TagEvent::create([
                    'entity_type' => 'document',
                    'entity_id' => $document->id,
                    'facet' => $facet,
                    'old_value' => $old,
                    'new_value' => $new,
                    'source' => 'admin_manual',
                    'actor_id' => $adminId,
                    'confidence' => null,
                    'note' => 'edited by admin',
                ]);
            }

            if ($validityChanged) {
                $oldVal = "{$document->validity_start?->toDateString()}..{$document->validity_end?->toDateString()}";
                $document->validity_start = $newStart;
                $document->validity_end = $newEnd;
                TagEvent::create([
                    'entity_type' => 'document',
                    'entity_id' => $document->id,
                    'facet' => 'validity',
                    'old_value' => $oldVal,
                    'new_value' => "{$newStart}..{$newEnd}",
                    'source' => 'admin_manual',
                    'actor_id' => $adminId,
                    'confidence' => null,
                    'note' => 'validity edited by admin',
                ]);
            }

            $document->save();
        });

        return response()->json(['status' => 'ok']);
    }

    /**
     * Tag the document with an existing APPROVED topic (FK picker — never free
     * text, never vocabulary creation). Writes the document_topics row
     * (source=admin_manual, verified by this admin) AND appends a `topic`
     * tag_events row. This is the FIRST writer of document_topics (Sprint 3);
     * AI-proposed topics arrive in Sprint 7.
     */
    public function addTopic(Request $request, string $uuid): JsonResponse
    {
        $data = $request->validate(['topic_id' => ['required', 'integer']]);
        $document = Document::where('uuid', $uuid)->firstOrFail();
        $adminId = $request->user()->id;

        $topic = Topic::where('id', $data['topic_id'])->where('status', 'approved')->first();
        if ($topic === null) {
            return response()->json(['message' => 'Topic not found in the approved vocabulary.'], 422);
        }
        if ($document->topics()->where('topics.id', $topic->id)->exists()) {
            return response()->json(['message' => 'Topic already applied.'], 422);
        }

        DB::transaction(function () use ($document, $topic, $adminId) {
            DocumentTopic::create([
                'document_id' => $document->id,
                'topic_id' => $topic->id,
                'source' => 'admin_manual',
                'confidence' => null,
                'verified_by' => $adminId,
                'verified_at' => now(),
            ]);

            TagEvent::create([
                'entity_type' => 'document',
                'entity_id' => $document->id,
                'facet' => 'topic',
                'old_value' => null,
                'new_value' => $topic->name,
                'source' => 'admin_manual',
                'actor_id' => $adminId,
                'confidence' => null,
                'note' => 'topic added by admin',
            ]);
        });

        return response()->json(['status' => 'ok']);
    }

    /** Remove a topic tag; appends a `topic` provenance row (old→null). Append-only. */
    public function removeTopic(Request $request, string $uuid, int $topicId): JsonResponse
    {
        $document = Document::where('uuid', $uuid)->firstOrFail();
        $adminId = $request->user()->id;

        $topic = Topic::find($topicId);
        if ($topic === null || ! $document->topics()->where('topics.id', $topicId)->exists()) {
            return response()->json(['message' => 'Topic is not applied to this document.'], 422);
        }

        DB::transaction(function () use ($document, $topic, $topicId, $adminId) {
            DocumentTopic::where('document_id', $document->id)->where('topic_id', $topicId)->delete();

            TagEvent::create([
                'entity_type' => 'document',
                'entity_id' => $document->id,
                'facet' => 'topic',
                'old_value' => $topic->name,
                'new_value' => null,
                'source' => 'admin_manual',
                'actor_id' => $adminId,
                'confidence' => null,
                'note' => 'topic removed by admin',
            ]);
        });

        return response()->json(['status' => 'ok']);
    }

    private function listRow(Document $d): array
    {
        return [
            'uuid' => $d->uuid,
            'title' => $d->title,
            'territory' => $d->convenio?->territory?->name,
            'sector' => $d->convenio?->sector?->name,
            'convenio' => $d->convenio?->numero,
            'document_type' => $d->documentType?->code,
            'validity_start' => $d->validity_start?->toDateString(),
            'validity_end' => $d->validity_end?->toDateString(),
            'retrieval_status' => $d->retrieval_status,
            'authority_level' => $d->authority_level,
            'tagging_status' => $d->tagging_status,
            'has_open_conflict' => (bool) $d->has_open_conflict,
            'has_open_review' => (bool) $d->has_open_review,
            'empty_text' => ((int) $d->pages_total) > 0 && ((int) $d->pages_with_text) === 0,
        ];
    }

    private function tags(Document $d): array
    {
        return [
            'convenio' => $d->convenio ? ['id' => $d->convenio->id, 'numero' => $d->convenio->numero, 'name' => $d->convenio->name] : null,
            'territory' => $d->convenio?->territory ? [
                'id' => $d->convenio->territory->id,
                'code' => $d->convenio->territory->code,
                'name' => $d->convenio->territory->name,
                'level' => $d->convenio->territory->level,
            ] : null,
            'sector' => $d->convenio?->sector ? ['id' => $d->convenio->sector->id, 'name' => $d->convenio->sector->name] : null,
            'document_type' => $d->documentType ? ['id' => $d->documentType->id, 'code' => $d->documentType->code, 'name' => $d->documentType->name] : null,
        ];
    }

    private function topFolder(string $relativePath): ?string
    {
        $parts = array_values(array_filter(explode('/', str_replace('\\', '/', $relativePath))));
        // Drop the filename (last segment).
        array_pop($parts);

        return $parts[0] ?? null;
    }
}
