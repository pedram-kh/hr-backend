<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Convenio;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\TagEvent;
use App\Services\DocumentIngestor;
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
        $document = Document::with(['convenio.territory', 'convenio.sector', 'documentType', 'pages', 'reviewTasks'])
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

            $isPdf = strtolower((string) $file->getClientOriginalExtension()) === 'pdf'
                || $file->getMimeType() === 'application/pdf';

            if (! $isPdf) {
                $results[] = [
                    'source_filename' => $original,
                    'skipped' => true,
                    'reason' => 'not a PDF (PDF-only this sprint)',
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
        $data = $request->validate(['value_id' => ['required', 'integer']]);
        $document = Document::where('uuid', $uuid)->firstOrFail();
        $adminId = $request->user()->id;

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
