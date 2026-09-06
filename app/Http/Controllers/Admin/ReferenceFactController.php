<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\ResolveFactDuplicateRequest;
use App\Http\Requests\StoreReferenceFactRequest;
use App\Http\Requests\UpdateReferenceFactRequest;
use App\Models\ConvenioJobCategory;
use App\Models\Document;
use App\Models\ReferenceFact;
use App\Models\TagEvent;
use App\Services\FactResolutionService;
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

    public function __construct(private readonly FactResolutionService $resolver) {}

    /** List/filter facts (read — open to any admin, incl. auditor). */
    public function index(Request $request): JsonResponse
    {
        $query = ReferenceFact::query()
            ->with(['convenio.territory', 'convenio.sector', 'jobCategory', 'topic', 'sourceDocument:id,uuid,title']);

        if ($request->filled('convenio_id')) {
            $query->where('convenio_id', $request->integer('convenio_id'));
        }
        if ($request->filled('topic_id')) {
            $query->where('topic_id', $request->integer('topic_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }
        if ($request->filled('source')) {
            $query->where('source', $request->string('source'));
        }

        // The AI-proposed Reference-facts review queue (Sprint 7b-2): the
        // uncertain-first sort is THE safety affordance — a flagged fact (scope
        // unclear / compound group / possible version) floats to the top, then
        // the least-confident, so the reviewer spends attention where the risk
        // is. Default scope: the inert ai_agent lane awaiting verification.
        if ($request->boolean('queue')) {
            $query->where('source', 'ai_agent')->where('status', 'needs_review');
            $query->orderByRaw('(uncertainty IS NOT NULL) DESC')
                ->orderByRaw('confidence ASC NULLS LAST')
                ->orderByDesc('id');
        } else {
            $query->orderByDesc('id');
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
            'duplicateOf:id,uuid,value',
            // Sprint 7d — the resolution verdict + version lineage.
            'supersededBy:id,uuid,value,validity_start', 'resolver:id,full_name',
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

    /**
     * Reject an AI proposal (Sprint 7b-2, Q5) → `status = rejected`, appended
     * provenance. Auditable + excluded from the queue WITHOUT deletion (over a
     * soft-delete timestamp). The agent's bad scope guess is preserved for the
     * eval/audit trail, never silently dropped. Gated by `knowledge.edit`.
     */
    public function reject(Request $request, string $uuid): JsonResponse
    {
        $fact = ReferenceFact::where('uuid', $uuid)->firstOrFail();
        $adminId = $request->user()->id;

        if ($fact->status === 'verified') {
            return response()->json(['message' => 'A verified fact cannot be rejected; edit or re-verify instead.'], 409);
        }
        if ($fact->status === 'rejected') {
            return response()->json(['status' => 'ok', 'fact_status' => 'rejected', 'note' => 'already rejected']);
        }

        DB::transaction(function () use ($fact, $adminId, $request) {
            $fact->update(['status' => 'rejected']);
            $note = trim((string) $request->input('reason', ''));
            $this->logEvent($fact->id, 'reference_fact', $fact->getOriginal('status'), 'rejected', $adminId, $note !== '' ? "rejected: {$note}" : 'AI proposal rejected by admin');
        });

        return response()->json(['status' => 'ok', 'fact_status' => 'rejected']);
    }

    /**
     * The SIDE-BY-SIDE duplicate pair (Sprint 7d, ADR-0024) — read.
     *
     * 7b-2 could only show a `≈ version` badge; the human had no way to see WHAT
     * differed, and no action. This returns both facts fully plus the list of
     * fields that actually differ, because the reviewer's whole job here is to see
     * the difference and decide: is this a new version, two rules that both apply,
     * or a bad flag?
     */
    public function duplicatePair(string $uuid): JsonResponse
    {
        $fact = ReferenceFact::with($this->pairRelations())->where('uuid', $uuid)->firstOrFail();

        // The pair can be reached from either side: a fact that FLAGS another, or a
        // fact another flagged.
        $counterpart = $fact->duplicate_of_id !== null
            ? ReferenceFact::with($this->pairRelations())->find($fact->duplicate_of_id)
            : ReferenceFact::with($this->pairRelations())->where('duplicate_of_id', $fact->id)->orderByDesc('id')->first();

        if ($counterpart === null) {
            return response()->json(['message' => 'Este hecho no tiene un duplicado marcado.'], 404);
        }

        return response()->json([
            'pair' => [$this->pairSide($fact), $this->pairSide($counterpart)],
            // Which fields differ — the muted/highlighted split in the UI. Computed
            // server-side so both the UI and an audit read the same comparison.
            'differing_fields' => $this->differingFields($fact, $counterpart),
            'resolved' => $fact->resolution !== null || $counterpart->resolution !== null,
            // A supersede needs a direction, and only the dates can justify one. The
            // UI uses this to pre-select (never to auto-apply) and to explain a 422.
            'supersede_candidate' => $this->supersedeCandidate($fact, $counterpart),
        ]);
    }

    /**
     * Resolve a flagged duplicate pair — `supersede` | `coexist` | `reject`
     * (Sprint 7d, ADR-0024). Gated `knowledge.edit`.
     *
     * All three are HUMAN-INVOKED and append-only in `tag_events`. A supersede
     * closes the older fact's validity window and NEVER deletes: the older value
     * stays `verified` and answerable for its own window, so a question dated in
     * the past still gets the answer that was true then.
     */
    public function resolveDuplicate(ResolveFactDuplicateRequest $request, string $uuid): JsonResponse
    {
        $fact = ReferenceFact::where('uuid', $uuid)->firstOrFail();
        $data = $request->validated();
        $adminId = $request->user()->id;
        $note = $data['note'] ?? null;

        if ($data['action'] === FactResolutionService::REJECT) {
            return response()->json($this->resolver->rejectDuplicate($fact, $adminId, $note));
        }

        $counterpart = $fact->duplicate_of_id !== null
            ? ReferenceFact::find($fact->duplicate_of_id)
            : ReferenceFact::where('duplicate_of_id', $fact->id)->orderByDesc('id')->first();

        if ($counterpart === null) {
            return response()->json(['message' => 'Este hecho no tiene un duplicado marcado.'], 404);
        }

        if ($data['action'] === FactResolutionService::COEXIST) {
            return response()->json($this->resolver->coexist($fact, $counterpart, $adminId, $note));
        }

        // supersede — the human names which side is newer; the service refuses if
        // the validity dates do not support that claim.
        $newer = collect([$fact, $counterpart])->firstWhere('uuid', $data['newer_uuid']);
        if ($newer === null) {
            return response()->json(['message' => 'newer_uuid no corresponde a ninguno de los dos hechos del par.'], 422);
        }
        $older = $newer->id === $fact->id ? $counterpart : $fact;

        try {
            return response()->json($this->resolver->supersede($newer, $older, $adminId, $note));
        } catch (\RuntimeException $e) {
            return response()->json([
                'code' => $e->getMessage(),
                'message' => $this->supersedeError($e->getMessage()),
            ], 422);
        }
    }

    /** @return list<string> */
    private function pairRelations(): array
    {
        return ['convenio.territory', 'convenio.sector', 'jobCategory', 'topic', 'sourceDocument:id,uuid,title,source_filename', 'resolver:id,full_name'];
    }

    /** @return array<string,mixed> */
    private function pairSide(ReferenceFact $f): array
    {
        return [
            'uuid' => $f->uuid,
            'id' => $f->id,
            'value' => $f->value,
            'raw_values' => $f->raw_values,
            'convenio' => $f->convenio?->numero,
            'convenio_name' => $f->convenio?->name,
            'territory' => $f->convenio?->territory?->name,
            'sector' => $f->convenio?->sector?->name,
            'job_category' => $f->jobCategory?->name,
            'group_label' => $f->group_label,
            'topic' => $f->topic?->name,
            'validity_start' => $f->validity_start?->toDateString(),
            'validity_end' => $f->validity_end?->toDateString(),
            'status' => $f->status,
            'source' => $f->source,
            'confidence' => $f->confidence,
            'uncertainty' => $f->uncertainty,
            'source_excerpt' => $f->source_excerpt,
            'source_document' => $f->sourceDocument ? [
                'uuid' => $f->sourceDocument->uuid,
                'title' => $f->sourceDocument->title,
                'source_filename' => $f->sourceDocument->source_filename,
            ] : null,
            'source_locator' => $f->source_locator,
            'resolution' => $f->resolution,
            'resolved_by' => $f->resolver?->full_name,
            'resolved_at' => $f->resolved_at?->toDateTimeString(),
            'superseded_by_id' => $f->superseded_by_id,
        ];
    }

    /** @return list<string> */
    private function differingFields(ReferenceFact $a, ReferenceFact $b): array
    {
        $fields = [
            'value' => fn (ReferenceFact $f) => trim((string) $f->value),
            'group_label' => fn (ReferenceFact $f) => \App\Support\GroupLabel::normalize($f->group_label),
            'job_category' => fn (ReferenceFact $f) => $f->job_category_id,
            'topic' => fn (ReferenceFact $f) => $f->topic_id,
            'convenio' => fn (ReferenceFact $f) => $f->convenio_id,
            'validity_start' => fn (ReferenceFact $f) => $f->validity_start?->toDateString(),
            'validity_end' => fn (ReferenceFact $f) => $f->validity_end?->toDateString(),
            'status' => fn (ReferenceFact $f) => $f->status,
            'source' => fn (ReferenceFact $f) => $f->source,
        ];

        $differing = [];
        foreach ($fields as $name => $extract) {
            if ($extract($a) !== $extract($b)) {
                $differing[] = $name;
            }
        }

        return $differing;
    }

    /**
     * Which side the dates would allow to be the newer one. Advisory only — the UI
     * pre-selects it, the human confirms it, and the service re-validates.
     *
     * @return array<string,mixed>
     */
    private function supersedeCandidate(ReferenceFact $a, ReferenceFact $b): array
    {
        foreach ([[$a, $b], [$b, $a]] as [$newer, $older]) {
            if ($newer->validity_start !== null
                && ($older->validity_start === null || $newer->validity_start->greaterThan($older->validity_start))) {
                return [
                    'possible' => true,
                    'newer_uuid' => $newer->uuid,
                    'older_uuid' => $older->uuid,
                    'would_close_older_at' => $newer->validity_start->copy()->subDay()->toDateString(),
                ];
            }
        }

        return [
            'possible' => false,
            'reason' => 'Las fechas de vigencia no permiten determinar cuál es la versión posterior '
                .'(faltan fechas o son iguales). Corrige la vigencia antes de sustituir, o marca que coexisten.',
        ];
    }

    private function supersedeError(string $code): string
    {
        return match ($code) {
            'same_fact' => 'No se puede sustituir un hecho por sí mismo.',
            'scope_mismatch' => 'Los dos hechos no comparten convenio y tema, así que no son versiones del mismo hecho.',
            'newer_has_no_validity_start' => 'La versión más reciente no tiene fecha de inicio de vigencia, '
                .'así que no hay límite con el que cerrar la vigencia del hecho anterior. Añade la fecha primero.',
            'newer_does_not_start_after_older' => 'La versión indicada como más reciente no empieza después de la anterior. '
                .'La dirección de una sustitución no se adivina: corrige las fechas o invierte la selección.',
            default => 'No se pudo aplicar la sustitución.',
        };
    }

    /**
     * Manually (re-)run the AI segmentation agent on a reference SOURCE (Sprint
     * 7b-2). Dispatches the queued SegmentReferenceSource job (the same job the
     * ingest auto-trigger uses); the proposed facts land inert (ai_agent/
     * needs_review). Idempotent — re-running upserts on the group_label-extended
     * logical key. Gated by `knowledge.edit`.
     */
    public function segment(Request $request, string $uuid): JsonResponse
    {
        $doc = Document::whereHas('documentType', fn ($q) => $q->where('code', 'reference_source'))
            ->where('uuid', $uuid)
            ->firstOrFail();

        \App\Jobs\SegmentReferenceSource::dispatch($doc->id);

        return response()->json(['status' => 'queued', 'document_uuid' => $doc->uuid]);
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
            'group_label' => $f->group_label,
            'topic' => $f->topic?->name,
            'status' => $f->status,
            'source' => $f->source,
            'authority_level' => $f->authority_level,
            'validity_start' => $f->validity_start?->toDateString(),
            'validity_end' => $f->validity_end?->toDateString(),
            // Sprint 7b-2 — the review-queue safety fields (uncertain-first sort,
            // fuchsia, the source-line check).
            'confidence' => $f->confidence,
            'uncertainty' => $f->uncertainty,
            'source_excerpt' => $f->source_excerpt,
            'is_ai_proposed' => $f->source === 'ai_agent' && $f->status === 'needs_review',
            'is_possible_duplicate' => $f->duplicate_of_id !== null,
            // Sprint 7d — the flag is now actionable, so the row has to say whether
            // it is still waiting on a human. An UNRESOLVED duplicate is the one that
            // needs attention; a resolved one keeps its lineage but leaves the queue.
            'resolution' => $f->resolution,
            'is_unresolved_duplicate' => $f->duplicate_of_id !== null && $f->resolution === null,
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
                // The group AS WRITTEN (Sprint 7b-2) — the identity discriminator
                // that carries the (common) null-job_category case.
                'group_label' => $fact->group_label,
            ],
            'topic' => $fact->topic ? ['id' => $fact->topic->id, 'name' => $fact->topic->name] : null,
            'validity_start' => $fact->validity_start?->toDateString(),
            'validity_end' => $fact->validity_end?->toDateString(),
            'authority_level' => $fact->authority_level,
            'source' => $fact->source,
            'status' => $fact->status,
            // Fuchsia is unverified-AI ONLY (ADR-0020): an ai_agent fact that is
            // still needs_review. A manual or verified fact never gets it.
            'is_ai_proposed' => $fact->source === 'ai_agent' && $fact->status === 'needs_review',
            // Sprint 7b-2 — the segmentation metadata the reviewer judges against.
            'confidence' => $fact->confidence,
            'uncertainty' => $fact->uncertainty,
            'source_excerpt' => $fact->source_excerpt,
            'proposal_batch_id' => $fact->proposal_batch_id,
            // The version/duplicate FLAG (Q4) — a signal in 7b-2; RESOLVABLE in 7d
            // (see resolveDuplicate). The link is retained after resolution as the
            // version lineage, so the flag is resolved rather than erased.
            'duplicate_of' => $fact->duplicateOf ? [
                'uuid' => $fact->duplicateOf->uuid,
                'value' => $fact->duplicateOf->value,
            ] : null,
            // Sprint 7d — the human's verdict and the version lineage.
            'resolution' => $fact->resolution,
            'resolved_by' => $fact->resolver?->full_name,
            'resolved_at' => $fact->resolved_at?->toDateTimeString(),
            'superseded_by' => $fact->supersededBy ? [
                'uuid' => $fact->supersededBy->uuid,
                'value' => $fact->supersededBy->value,
                'validity_start' => $fact->supersededBy->validity_start?->toDateString(),
            ] : null,
            'is_unresolved_duplicate' => $fact->duplicate_of_id !== null && $fact->resolution === null,
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
