<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreReferenceFactRequest;
use App\Http\Requests\UpdateReferenceFactRequest;
use App\Models\ConvenioJobCategory;
use App\Models\Document;
use App\Models\ReferenceFact;
use App\Models\TagEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Structured Reference Knowledge — the MANUAL create/verify path (Sprint 7b-1,
 * ADR-0021). No AI here: the `ai_agent` source lane is reserved-but-unwritten
 * (it lights in 7b-2); the manual path is the only writer.
 *
 * Reuses the established discipline:
 *  - inert until verified (the 7a/ADR-0020 spine): a fact lands `needs_review`
 *    and is not answerable until a human verifies (and since 7c is not built, NO
 *    fact is answerable yet — correct);
 *  - append-only provenance in `tag_events` (entity_type = 'reference_fact') —
 *    never an UPDATE/DELETE of history;
 *  - the Sprint-3 scope-edit confirm gate (409 + `confirm_scope_change`);
 *  - authority-low BY CONSTRUCTION (the enum column + the FormRequest 422).
 *
 * Reads are open to any admin; WRITES are gated by `knowledge.edit` at the route.
 */
class ReferenceFactController extends Controller
{
    /** Edits that move which employees a fact would answer (once 7c exists). */
    private const SCOPE_FACETS = ['convenio_id', 'job_category_id', 'validity_start', 'validity_end'];

    /** List/filter facts (read — open to any admin, incl. auditor). */
    public function index(Request $request): JsonResponse
    {
        $query = ReferenceFact::query()
            ->with(['convenio.territory', 'convenio.sector', 'jobCategory', 'topic', 'sourceDocument:id,uuid,title'])
            ->orderByDesc('id');

        if ($request->filled('convenio_id')) {
            $query->where('convenio_id', $request->integer('convenio_id'));
        }
        if ($request->filled('topic_id')) {
            $query->where('topic_id', $request->integer('topic_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        return response()->json([
            'facts' => $query->paginate(50)->through(fn (ReferenceFact $f) => $this->listRow($f)),
        ]);
    }

    /** The fact card (read). */
    public function show(string $uuid): JsonResponse
    {
        $fact = ReferenceFact::with([
            'convenio.territory', 'convenio.sector', 'jobCategory', 'topic',
            'sourceDocument:id,uuid,title,source_filename', 'verifier:id,full_name', 'creator:id,full_name',
        ])->where('uuid', $uuid)->firstOrFail();

        $provenance = TagEvent::where('entity_type', 'reference_fact')
            ->where('entity_id', $fact->id)
            ->orderBy('created_at')->orderBy('id')
            ->get(['facet', 'old_value', 'new_value', 'source', 'actor_id', 'confidence', 'note', 'created_at']);

        return response()->json($this->card($fact, $provenance));
    }

    /** Create a scoped fact → lands `needs_review`, provenance appended (knowledge.edit). */
    public function store(StoreReferenceFactRequest $request): JsonResponse
    {
        $data = $request->validated();

        if (($err = $this->validateScopeBindings($data)) !== null) {
            return response()->json(['message' => $err], 422);
        }

        $adminId = $request->user()->id;

        $fact = DB::transaction(function () use ($data, $adminId) {
            $fact = ReferenceFact::create([
                'convenio_id' => $data['convenio_id'],
                'job_category_id' => $data['job_category_id'] ?? null,
                'topic_id' => $data['topic_id'] ?? null,
                'value' => $data['value'],
                'raw_values' => $data['raw_values'] ?? null,
                'validity_start' => $data['validity_start'] ?? null,
                'validity_end' => $data['validity_end'] ?? null,
                // INVARIANT 1: forced to the floor regardless of input (the
                // FormRequest already rejects anything higher with 422).
                'authority_level' => ReferenceFact::AUTHORITY_LEVEL,
                'source' => 'admin_manual', // ai_agent reserved for 7b-2
                'status' => 'needs_review',  // inert until a human verifies
                'source_document_id' => $data['source_document_id'] ?? null,
                'source_locator' => $data['source_locator'] ?? null,
                'created_by' => $adminId,
            ]);

            $this->logEvent($fact->id, 'reference_fact', null, 'needs_review', $adminId, 'reference fact created');
            $this->logEvent($fact->id, 'value', null, $fact->value, $adminId, 'value set');

            return $fact;
        });

        return response()->json(['status' => 'ok', 'uuid' => $fact->uuid], 201);
    }

    /**
     * Bounded edit. A scope-affecting change (convenio / job_category / validity)
     * requires `confirm_scope_change=true`, else 409 (the Sprint-3 gate). Every
     * changed field appends an `admin_manual` `tag_events` row — append-only.
     */
    public function update(UpdateReferenceFactRequest $request, string $uuid): JsonResponse
    {
        $fact = ReferenceFact::where('uuid', $uuid)->firstOrFail();
        $data = $request->validated();
        $adminId = $request->user()->id;

        if (($err = $this->validateScopeBindings(array_merge($fact->only(['convenio_id']), $data))) !== null) {
            return response()->json(['message' => $err], 422);
        }

        // Determine changed fields (only those present and different).
        $editable = ['convenio_id', 'job_category_id', 'topic_id', 'value', 'validity_start', 'validity_end', 'source_document_id', 'source_locator', 'raw_values'];
        $changes = [];
        foreach ($editable as $field) {
            if (! array_key_exists($field, $data)) {
                continue;
            }
            $old = $fact->{$field};
            $oldCmp = $old instanceof \Illuminate\Support\Carbon ? $old->toDateString() : $old;
            $new = $data[$field];
            if ($field === 'raw_values') {
                if (json_encode($old) === json_encode($new)) {
                    continue;
                }
            } elseif ((string) $oldCmp === (string) $new) {
                continue;
            }
            $changes[$field] = [$oldCmp, $new];
        }

        if ($changes === []) {
            return response()->json(['status' => 'ok', 'note' => 'no change']);
        }

        $scopeAffecting = array_intersect(array_keys($changes), self::SCOPE_FACETS) !== [];
        if ($scopeAffecting && ! $request->boolean('confirm_scope_change')) {
            return response()->json([
                'message' => 'This changes the scope of the fact (which employees it would answer). Re-send with confirm_scope_change=true to apply.',
                'scope_affecting' => true,
            ], 409);
        }

        DB::transaction(function () use ($fact, $changes, $adminId) {
            foreach ($changes as $field => [$old, $new]) {
                $fact->{$field} = $new;
            }
            $fact->save();

            foreach ($changes as $field => [$old, $new]) {
                $this->logEvent(
                    $fact->id,
                    $field,
                    $old === null ? null : (is_array($old) ? json_encode($old) : (string) $old),
                    $new === null ? null : (is_array($new) ? json_encode($new) : (string) $new),
                    $adminId,
                    'edited by admin',
                );
            }
        });

        return response()->json(['status' => 'ok']);
    }

    /**
     * Verify → flip `needs_review` to `verified`, set verified_by/verified_at,
     * append provenance (the 7a spine; mirrors DocumentController::confirm).
     * Gated by `knowledge.edit` (Q6 — same human is author + verifier in 7b-1;
     * the verify bar gets the harder look when 7b-2 adds the AI proposer).
     */
    public function verify(Request $request, string $uuid): JsonResponse
    {
        $fact = ReferenceFact::where('uuid', $uuid)->firstOrFail();
        $adminId = $request->user()->id;

        if ($fact->status === 'verified') {
            return response()->json(['status' => 'ok', 'fact_status' => 'verified', 'note' => 'already verified']);
        }

        DB::transaction(function () use ($fact, $adminId) {
            $fact->update([
                'status' => 'verified',
                'verified_by' => $adminId,
                'verified_at' => now(),
            ]);
            $this->logEvent($fact->id, 'reference_fact', 'needs_review', 'verified', $adminId, 'reference fact verified');
        });

        return response()->json(['status' => 'ok', 'fact_status' => 'verified']);
    }

    /** Reference SOURCE documents (read) — the create-form source picker. */
    public function sources(): JsonResponse
    {
        $docs = Document::query()
            ->whereHas('documentType', fn ($q) => $q->where('code', 'reference_source'))
            ->orderByDesc('id')
            ->get(['id', 'uuid', 'title', 'source_filename']);

        return response()->json(['sources' => $docs]);
    }

    /**
     * The extracted content of a reference SOURCE (read) — surfaced for manual
     * fact entry (the reader view). These are the display `document_pages` stored
     * at ingest from hr-ai /read-structured; never embedded (ADR-0006).
     */
    public function sourceContent(string $uuid): JsonResponse
    {
        $doc = Document::with('pages')
            ->whereHas('documentType', fn ($q) => $q->where('code', 'reference_source'))
            ->where('uuid', $uuid)
            ->firstOrFail();

        return response()->json([
            'uuid' => $doc->uuid,
            'title' => $doc->title,
            'pages' => $doc->pages->map(fn ($p) => [
                'page_number' => $p->page_number,
                'text' => $p->text,
            ])->values(),
        ]);
    }

    // ---- helpers ------------------------------------------------------------

    /**
     * Enforce that the (optional) job category belongs to the chosen convenio,
     * and the (optional) source document is a reference_source. Returns an error
     * string or null.
     *
     * @param  array<string,mixed>  $data
     */
    private function validateScopeBindings(array $data): ?string
    {
        $convenioId = $data['convenio_id'] ?? null;

        if (! empty($data['job_category_id']) && $convenioId !== null) {
            $belongs = ConvenioJobCategory::where('id', $data['job_category_id'])
                ->where('convenio_id', $convenioId)->exists();
            if (! $belongs) {
                return 'The job category does not belong to the selected convenio.';
            }
        }

        if (! empty($data['source_document_id'])) {
            $isRef = Document::where('id', $data['source_document_id'])
                ->whereHas('documentType', fn ($q) => $q->where('code', 'reference_source'))
                ->exists();
            if (! $isRef) {
                return 'The source document must be a reference_source document.';
            }
        }

        return null;
    }

    private function logEvent(int $factId, string $facet, ?string $old, ?string $new, int $adminId, string $note): void
    {
        TagEvent::create([
            'entity_type' => 'reference_fact',
            'entity_id' => $factId,
            'facet' => $facet,
            'old_value' => $old,
            'new_value' => $new,
            'source' => 'admin_manual', // ai_agent reserved for 7b-2
            'actor_id' => $adminId,
            'confidence' => null,
            'note' => $note,
        ]);
    }

    /** @return array<string,mixed> */
    private function listRow(ReferenceFact $f): array
    {
        return [
            'uuid' => $f->uuid,
            'value' => $f->value,
            'convenio' => $f->convenio?->numero,
            'territory' => $f->convenio?->territory?->name,
            'sector' => $f->convenio?->sector?->name,
            'job_category' => $f->jobCategory?->name,
            'topic' => $f->topic?->name,
            'status' => $f->status,
            'source' => $f->source,
            'authority_level' => $f->authority_level,
            'validity_start' => $f->validity_start?->toDateString(),
            'validity_end' => $f->validity_end?->toDateString(),
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int,TagEvent>  $provenance
     * @return array<string,mixed>
     */
    private function card(ReferenceFact $fact, $provenance): array
    {
        return [
            'uuid' => $fact->uuid,
            'value' => $fact->value,
            'raw_values' => $fact->raw_values,
            'scope' => [
                'convenio' => $fact->convenio ? ['id' => $fact->convenio->id, 'numero' => $fact->convenio->numero, 'name' => $fact->convenio->name] : null,
                // Derived (data-model §5) — shown read-only as "(derived)".
                'territory' => $fact->convenio?->territory ? ['name' => $fact->convenio->territory->name, 'level' => $fact->convenio->territory->level] : null,
                'sector' => $fact->convenio?->sector ? ['name' => $fact->convenio->sector->name] : null,
                'job_category' => $fact->jobCategory ? ['id' => $fact->jobCategory->id, 'name' => $fact->jobCategory->name, 'group_code' => $fact->jobCategory->group_code] : null,
            ],
            'topic' => $fact->topic ? ['id' => $fact->topic->id, 'name' => $fact->topic->name] : null,
            'validity_start' => $fact->validity_start?->toDateString(),
            'validity_end' => $fact->validity_end?->toDateString(),
            'authority_level' => $fact->authority_level,
            'source' => $fact->source,
            'status' => $fact->status,
            // Fuchsia is unverified-AI ONLY (ADR-0020). A manual fact never gets
            // it; this is always false in 7b-1 (no ai_agent writer exists yet).
            'is_ai_proposed' => $fact->source === 'ai_agent' && $fact->status === 'needs_review',
            'verified_by' => $fact->verifier?->full_name,
            'verified_at' => $fact->verified_at?->toDateTimeString(),
            'created_by' => $fact->creator?->full_name,
            'source_document' => $fact->sourceDocument ? [
                'uuid' => $fact->sourceDocument->uuid,
                'title' => $fact->sourceDocument->title,
                'source_filename' => $fact->sourceDocument->source_filename,
            ] : null,
            'source_locator' => $fact->source_locator,
            'provenance' => $provenance,
        ];
    }
}
