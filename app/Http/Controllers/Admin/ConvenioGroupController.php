<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ProposeConvenioGroups;
use App\Models\Convenio;
use App\Models\ConvenioGroup;
use App\Models\ConvenioGroupCategory;
use App\Models\ReferenceFact;
use App\Models\ReferenceFactGroupScope;
use App\Models\TagEvent;
use App\Support\FactGroupBindingPlanner;
use App\Support\GroupCodeNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sprint 7f (ADR-0028) — the Groups review surface.
 *
 * The human half of propose-then-approve. Reads are open to any admin; every
 * write is behind `ability:knowledge.edit`, like the reference-fact surface.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * THE ONE RULE THAT MAKES THIS SAFE: `approve()` NEVER WRITES A BINDING.
 *
 * Approving a node makes it real (Phase 3's matcher joins `status='approved'`),
 * and a real node with facts wired to it is what answers an employee's
 * question. So approval is a TWO-STEP confirmation: `GET .../binding-diff`
 * returns exactly which facts would bind and which would not, and `approve()`
 * only writes the `reference_fact_group_scopes` rows the reviewer sends back in
 * `confirmed_fact_ids`. A fact the reviewer did not tick is not bound, and a
 * fact the planner could not resolve is never bound implicitly.
 *
 * The whole sprint exists because a matcher bound scopes without being asked.
 * ────────────────────────────────────────────────────────────────────────────
 */
class ConvenioGroupController extends Controller
{
    public function __construct(private FactGroupBindingPlanner $planner) {}

    /**
     * Convenios with a group structure or a reason to have one, for the tab's
     * left-hand list. A convenio with group-scoped facts and no structure is the
     * interesting case, so it is counted rather than hidden.
     */
    public function index(): JsonResponse
    {
        $convenios = Convenio::query()
            ->with('territory:id,name')
            ->orderBy('name')
            ->get()
            ->map(function (Convenio $c) {
                $counts = ConvenioGroup::query()
                    ->where('convenio_id', $c->id)
                    ->selectRaw('status, count(*) as n')
                    ->groupBy('status')
                    ->pluck('n', 'status');

                return [
                    'id' => $c->id,
                    'name' => (string) $c->name,
                    'territory' => $c->territory?->name,
                    'pending' => (int) ($counts[ConvenioGroup::STATUS_NEEDS_REVIEW] ?? 0),
                    'approved' => (int) ($counts[ConvenioGroup::STATUS_APPROVED] ?? 0),
                    'rejected' => (int) ($counts[ConvenioGroup::STATUS_REJECTED] ?? 0),
                    'group_scoped_facts' => ReferenceFact::where('convenio_id', $c->id)
                        ->whereNotNull('group_label')->count(),
                ];
            })
            ->filter(fn ($r) => $r['pending'] || $r['approved'] || $r['rejected'] || $r['group_scoped_facts'])
            ->values();

        return response()->json(['convenios' => $convenios]);
    }

    /**
     * One convenio's whole tree: every node with its excerpt, its proposed and
     * approved category memberships, and the facts whose `group_label` would
     * bind to it. This is the review screen's single payload.
     */
    public function show(int $convenioId): JsonResponse
    {
        $convenio = Convenio::with('territory:id,name')->findOrFail($convenioId);

        $nodes = ConvenioGroup::query()
            ->where('convenio_id', $convenioId)
            ->with(['approvedBy:id,full_name', 'jobCategories:id,name,group_code'])
            ->orderByRaw('parent_id NULLS FIRST')
            ->orderBy('code_normalized')
            ->get();

        $memberships = ConvenioGroupCategory::query()
            ->whereIn('convenio_group_id', $nodes->pluck('id'))
            ->with('jobCategory:id,name,group_code')
            ->get()
            ->groupBy('convenio_group_id');

        $facts = $this->convenioFacts($convenioId);
        $approved = $nodes->where('status', ConvenioGroup::STATUS_APPROVED);
        $plans = collect($this->planner->planMany($facts, $approved));
        $boundByFact = ReferenceFactGroupScope::query()
            ->whereIn('reference_fact_id', $facts->pluck('id'))
            ->get()
            ->groupBy('reference_fact_id');

        $roots = $nodes->whereNull('parent_id');

        return response()->json([
            'convenio' => [
                'id' => $convenio->id,
                'name' => (string) $convenio->name,
                'territory' => $convenio->territory?->name,
            ],
            'tree' => $roots->map(fn (ConvenioGroup $root) => $this->nodeRow(
                $root,
                $nodes->where('parent_id', $root->id),
                $memberships,
                $plans,
                $boundByFact,
            ))->values(),
            'orphans' => $nodes
                ->filter(fn (ConvenioGroup $n) => $n->parent_id !== null && ! $roots->contains('id', $n->parent_id))
                ->map(fn (ConvenioGroup $n) => $this->nodeRow($n, collect(), $memberships, $plans, $boundByFact))
                ->values(),
            // Flat, parent-first, for the manual-binding picker on the unbound
            // list below: a reviewer choosing a node needs to see "Grupo 2 ›
            // resto áreas", not a bare label that could belong to any group.
            'approved_nodes' => $approved
                ->sortBy(fn (ConvenioGroup $n) => [$n->parent_id === null ? 0 : 1, $n->code_normalized])
                ->map(fn (ConvenioGroup $n) => [
                    'id' => $n->id,
                    'path_label' => $n->parent_id !== null
                        ? (($nodes->firstWhere('id', $n->parent_id)?->label ?? '?').' › '.$n->label)
                        : $n->label,
                ])
                ->values(),
            'unbindable_facts' => $plans
                ->filter(fn ($p) => $p['status'] !== FactGroupBindingPlanner::STATUS_RESOLVED
                    && $p['status'] !== FactGroupBindingPlanner::STATUS_CONVENIO_WIDE)
                ->map(fn ($p) => [
                    'fact_id' => $p['fact_id'],
                    'fact_uuid' => $p['fact_uuid'],
                    'fact_status' => $p['fact_status'],
                    'group_label' => $p['group_label'],
                    'value' => $p['value'],
                    'status' => $p['status'],
                    'kind' => $p['kind'],
                    'reason' => $p['reason'],
                    'already_bound' => $boundByFact->has($p['fact_id']),
                    // A manually bound fact still appears here, because the
                    // planner still cannot read its label — that is the honest
                    // state. But it must not read as UNRESOLVED: name the nodes
                    // a human put it on, or the list looks like a standing
                    // verdict and someone binds it a second time.
                    'bound_to' => $boundByFact->get($p['fact_id'], collect())
                        ->map(fn (ReferenceFactGroupScope $s) => $nodes->firstWhere('id', $s->convenio_group_id)?->label)
                        ->filter()
                        ->values(),
                ])
                ->values(),
        ]);
    }

    /**
     * What approving this node would do to fact bindings. THE confirmation step:
     * nothing is written here, and `approve()` writes only what comes back.
     */
    public function bindingDiff(int $groupId): JsonResponse
    {
        $group = ConvenioGroup::with('convenio:id,name')->findOrFail($groupId);

        // Plan against the tree AS IT WOULD BE with this node approved, so the
        // diff answers "what happens if I approve THIS", not "what is true now".
        $prospective = ConvenioGroup::query()
            ->where('convenio_id', $group->convenio_id)
            ->where(function ($q) use ($group) {
                $q->where('status', ConvenioGroup::STATUS_APPROVED)->orWhere('id', $group->id);
            })
            ->get();

        $facts = $this->convenioFacts($group->convenio_id);
        $plans = collect($this->planner->planMany($facts, $prospective));

        $alreadyBound = ReferenceFactGroupScope::query()
            ->where('convenio_group_id', $group->id)
            ->pluck('reference_fact_id')
            ->all();

        $wouldBind = $plans
            ->filter(fn ($p) => $p['status'] === FactGroupBindingPlanner::STATUS_RESOLVED
                && in_array($group->id, $p['node_ids'], true))
            ->map(fn ($p) => [
                'fact_id' => $p['fact_id'],
                'fact_uuid' => $p['fact_uuid'],
                'fact_status' => $p['fact_status'],
                'group_label' => $p['group_label'],
                'value' => $p['value'],
                'validity_start' => $p['validity_start'],
                'validity_end' => $p['validity_end'],
                'kind' => $p['kind'],
                'also_binds_to_node_ids' => array_values(array_diff($p['node_ids'], [$group->id])),
                'already_bound' => in_array($p['fact_id'], $alreadyBound, true),
            ])
            ->values();

        return response()->json([
            'group' => $this->groupSummary($group),
            'would_bind' => $wouldBind,
            'needs_manual_binding' => $plans
                ->filter(fn ($p) => $p['status'] === FactGroupBindingPlanner::STATUS_NEEDS_HUMAN)
                ->map(fn ($p) => [
                    'fact_id' => $p['fact_id'],
                    'group_label' => $p['group_label'],
                    'value' => $p['value'],
                    'kind' => $p['kind'],
                    'reason' => $p['reason'],
                ])
                ->values(),
            'note' => 'Aprobar el nodo no vincula nada por sí solo. Solo se escriben los datos '
                .'que confirmes en `confirmed_fact_ids`.',
        ]);
    }

    /**
     * Approve one node, and bind ONLY the confirmed facts.
     *
     * `confirmed_fact_ids` is required-but-may-be-empty on purpose: an empty
     * array is a real, meaningful answer ("this node is right, bind nothing
     * yet"), and making it explicit means a client that forgets the field
     * cannot silently bind a whole diff.
     */
    public function approve(Request $request, int $groupId): JsonResponse
    {
        $group = ConvenioGroup::findOrFail($groupId);
        $adminId = $request->user()->id;

        $data = $request->validate([
            'confirmed_fact_ids' => ['present', 'array'],
            'confirmed_fact_ids.*' => ['integer'],
            'confirmed_category_ids' => ['sometimes', 'array'],
            'confirmed_category_ids.*' => ['integer'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        if ($group->status === ConvenioGroup::STATUS_REJECTED) {
            throw ValidationException::withMessages([
                'status' => 'Este nodo fue rechazado. Reabrirlo es una acción explícita, no una aprobación.',
            ]);
        }

        // A sub-area cannot be approved before its parent: an approved child of a
        // pending parent would be a node the tree cannot reach, and the matcher
        // would see a sub-area with no group.
        if ($group->parent_id !== null) {
            $parent = ConvenioGroup::find($group->parent_id);
            if ($parent === null || $parent->status !== ConvenioGroup::STATUS_APPROVED) {
                throw ValidationException::withMessages([
                    'parent_id' => 'Aprueba primero el grupo padre: un área aprobada bajo un grupo '
                        .'pendiente sería inalcanzable.',
                ]);
            }
        }

        $confirmed = array_values(array_unique($data['confirmed_fact_ids']));

        DB::transaction(function () use ($group, $adminId, $confirmed, $data) {
            $wasStatus = $group->status;

            if ($wasStatus !== ConvenioGroup::STATUS_APPROVED) {
                $group->update([
                    'status' => ConvenioGroup::STATUS_APPROVED,
                    'approved_by' => $adminId,
                    'approved_at' => now(),
                ]);
                $this->logEvent($group->id, $wasStatus, ConvenioGroup::STATUS_APPROVED, $adminId,
                    'nodo de grupo aprobado'.(($data['note'] ?? '') !== '' ? ': '.$data['note'] : ''));
            }

            $this->bindConfirmedFacts($group, $confirmed, $adminId, override: false,
                context: 'tras confirmar el diff de aprobación');

            if (array_key_exists('confirmed_category_ids', $data)) {
                $this->approveCategories($group, $data['confirmed_category_ids'], $adminId);
            }
        });

        return response()->json([
            'status' => 'ok',
            'group' => $this->groupSummary($group->fresh()),
            'bound_fact_ids' => $confirmed,
        ]);
    }

    /**
     * Edit a pending node's printed label, its excerpt, or its parent — the
     * "edit" of approve/edit/reject. The reviewer is correcting the AI's
     * reading, so this re-derives `code_normalized` from the corrected label
     * with the same normalizer, and shows them the key it produced.
     */
    public function update(Request $request, int $groupId): JsonResponse
    {
        $group = ConvenioGroup::findOrFail($groupId);
        $adminId = $request->user()->id;

        $data = $request->validate([
            'label' => ['sometimes', 'string', 'max:255'],
            'source_excerpt' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'parent_id' => ['sometimes', 'nullable', 'integer'],
        ]);

        if ($group->status === ConvenioGroup::STATUS_APPROVED && array_key_exists('label', $data)) {
            // Renaming an approved node changes the key Phase 3 compares, under
            // facts and employees already attached to it. Reject rather than
            // cascade: the reviewer should reject-and-repropose deliberately.
            throw ValidationException::withMessages([
                'label' => 'Este nodo ya está aprobado. Cambiar su etiqueta cambiaría la clave que '
                    .'compara el emparejador con datos y personas ya vinculados. Recházalo y vuelve a proponerlo.',
            ]);
        }

        if (array_key_exists('parent_id', $data) && $data['parent_id'] !== null) {
            $parent = ConvenioGroup::find($data['parent_id']);
            if ($parent === null || $parent->convenio_id !== $group->convenio_id) {
                throw ValidationException::withMessages([
                    'parent_id' => 'El grupo padre debe pertenecer al mismo convenio.',
                ]);
            }
            if ($parent->parent_id !== null) {
                throw ValidationException::withMessages([
                    'parent_id' => 'Solo hay dos niveles: un área no puede colgar de otra área.',
                ]);
            }
        }

        $changes = [];
        if (array_key_exists('label', $data)) {
            $label = trim($data['label']);
            $explained = GroupCodeNormalizer::explain($label);
            if ($explained['code'] === '') {
                throw ValidationException::withMessages([
                    'label' => 'Esa etiqueta no produce ninguna clave comparable (p. ej. "Grupo" a secas). '
                        .'Escribe la etiqueta tal como la imprime el convenio.',
                ]);
            }
            $changes['label'] = $label;
            $changes['code_normalized'] = $explained['code'];
        }
        if (array_key_exists('source_excerpt', $data)) {
            $changes['source_excerpt'] = $data['source_excerpt'];
        }
        if (array_key_exists('parent_id', $data)) {
            $changes['parent_id'] = $data['parent_id'];
        }

        if ($changes === []) {
            return response()->json(['status' => 'ok', 'group' => $this->groupSummary($group), 'note' => 'sin cambios']);
        }

        DB::transaction(function () use ($group, $changes, $adminId) {
            $before = $group->label.' → '.$group->code_normalized;
            $group->fill($changes);
            // A human edit takes ownership of the row: it is no longer the AI's
            // proposal, so re-running the proposer will not overwrite it.
            $group->source = ConvenioGroup::SOURCE_MANUAL;
            $group->save();

            $this->logEvent($group->id, $before, $group->label.' → '.$group->code_normalized, $adminId,
                'nodo de grupo editado por una persona (la fuente pasa a admin_manual)');
        });

        return response()->json([
            'status' => 'ok',
            'group' => $this->groupSummary($group->fresh()),
        ]);
    }

    /**
     * Reject a node. An approved node with bindings cannot be rejected out from
     * under them — unbind first, deliberately.
     */
    public function reject(Request $request, int $groupId): JsonResponse
    {
        $group = ConvenioGroup::findOrFail($groupId);
        $adminId = $request->user()->id;
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:2000']]);

        $bindings = ReferenceFactGroupScope::where('convenio_group_id', $group->id)->count();
        if ($bindings > 0) {
            throw ValidationException::withMessages([
                'bindings' => "Este nodo tiene {$bindings} dato(s) de referencia vinculados. "
                    .'Desvincúlalos primero: rechazarlo dejaría esos datos sin ámbito.',
            ]);
        }

        $children = ConvenioGroup::where('parent_id', $group->id)
            ->where('status', '!=', ConvenioGroup::STATUS_REJECTED)->count();
        if ($children > 0) {
            throw ValidationException::withMessages([
                'children' => "Este grupo tiene {$children} área(s) activa(s). Recházalas primero.",
            ]);
        }

        DB::transaction(function () use ($group, $adminId, $data) {
            $was = $group->status;
            $group->update(['status' => ConvenioGroup::STATUS_REJECTED, 'approved_by' => null, 'approved_at' => null]);
            ConvenioGroupCategory::where('convenio_group_id', $group->id)
                ->update(['status' => ConvenioGroupCategory::STATUS_REJECTED]);
            $this->logEvent($group->id, $was, ConvenioGroup::STATUS_REJECTED, $adminId,
                'nodo de grupo rechazado'.(($data['reason'] ?? '') !== '' ? ': '.$data['reason'] : ''));
        });

        return response()->json(['status' => 'ok', 'group' => $this->groupSummary($group->fresh())]);
    }

    /**
     * Bind facts to an ALREADY-APPROVED node, outside the approval moment.
     *
     * Approval used to be the only moment a binding could be created, which
     * made a reviewer's first pass final: approve a node with a fact unticked
     * and there was no way back to it. Binding is a separate decision from
     * approval and now has its own door.
     *
     * TWO LANES, and the difference between them is the whole point of this
     * sprint:
     *
     *   GRAMMAR (default) — the planner resolves the fact's `group_label` to
     *     this node. The reviewer's ids authorize; the grammar still checks.
     *
     *   OVERRIDE (`override: true`) — the planner REFUSES the label, and a
     *     human decides anyway. This is legitimate and expected: "Grupo 2
     *     excepto área cinco" does mean `resto áreas`, but only because someone
     *     read the convenio. The planner declining to infer that is correct;
     *     a human asserting it is also correct. What must never happen is the
     *     MACHINE inferring it silently, which is what the digit matcher did.
     *
     * So override is not a hole in the validation — it is the human authority
     * the validation exists to defer to, and it is recorded as such: the
     * `tag_events` row keeps the planner's refusal reason alongside the
     * decision, so a later reader can see this scope was asserted rather than
     * read.
     *
     * Override is refused when the planner resolves the label to a DIFFERENT
     * node. That is not a judgement call, it is a contradiction — and the fix
     * is to correct the fact's label, not to bind past it.
     */
    public function bind(Request $request, int $groupId): JsonResponse
    {
        $group = ConvenioGroup::findOrFail($groupId);
        $adminId = $request->user()->id;

        $data = $request->validate([
            'fact_ids' => ['required', 'array', 'min:1'],
            'fact_ids.*' => ['integer'],
            'override' => ['sometimes', 'boolean'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        if ($group->status !== ConvenioGroup::STATUS_APPROVED) {
            throw ValidationException::withMessages([
                'status' => 'Solo se puede vincular a un nodo aprobado: un nodo pendiente no existe '
                    .'todavía para el emparejador.',
            ]);
        }

        $factIds = array_values(array_unique($data['fact_ids']));
        $override = (bool) ($data['override'] ?? false);

        $bound = DB::transaction(fn () => $this->bindConfirmedFacts(
            $group,
            $factIds,
            $adminId,
            $override,
            context: $override
                ? 'decisión humana sobre una etiqueta que el analizador no resuelve'
                : 'vinculación posterior a la aprobación',
            note: $data['note'] ?? null,
        ));

        return response()->json([
            'status' => 'ok',
            'group' => $this->groupSummary($group->fresh()),
            'bound_fact_ids' => $bound,
        ]);
    }

    /**
     * Shared by `approve()` and `bind()`. Re-plans INSIDE the caller's
     * transaction so a stale or hand-edited payload cannot bind a fact whose
     * label points somewhere else.
     *
     * @param  list<int>  $factIds
     * @return list<int>
     */
    private function bindConfirmedFacts(
        ConvenioGroup $group,
        array $factIds,
        int $adminId,
        bool $override,
        string $context,
        ?string $note = null,
    ): array {
        if ($factIds === []) {
            return [];
        }

        $approved = ConvenioGroup::where('convenio_id', $group->convenio_id)
            ->where('status', ConvenioGroup::STATUS_APPROVED)
            ->get();
        $facts = $this->convenioFacts($group->convenio_id)->whereIn('id', $factIds);
        $plans = collect($this->planner->planMany($facts, $approved))->keyBy('fact_id');

        $done = [];

        foreach ($factIds as $factId) {
            $plan = $plans->get($factId);

            if ($plan === null) {
                // Not a fact of this convenio (or rejected). A scope is only
                // meaningful within its own convenio.
                throw ValidationException::withMessages([
                    'fact_ids' => "El dato {$factId} no pertenece a este convenio.",
                ]);
            }

            $resolvesHere = $plan['status'] === FactGroupBindingPlanner::STATUS_RESOLVED
                && in_array($group->id, $plan['node_ids'], true);
            $manual = false;

            if (! $resolvesHere) {
                if (! $override) {
                    throw ValidationException::withMessages([
                        'fact_ids' => "El dato {$factId} no se resuelve a este nodo. "
                            .'Vuelve a cargar el diff, o vincúlalo explícitamente como decisión humana.',
                    ]);
                }

                if ($plan['status'] === FactGroupBindingPlanner::STATUS_CONVENIO_WIDE) {
                    // A convenio-wide fact already answers, at Tier 3. Giving it
                    // a group would NARROW a rule that applies to everyone.
                    throw ValidationException::withMessages([
                        'fact_ids' => "El dato {$factId} es de ámbito convenio: vincularlo a un grupo "
                            .'restringiría una norma que se aplica a toda la plantilla.',
                    ]);
                }

                if ($plan['status'] === FactGroupBindingPlanner::STATUS_RESOLVED) {
                    // Resolves elsewhere. Not a judgement call — a contradiction.
                    $others = implode(', ', $plan['node_ids']);
                    throw ValidationException::withMessages([
                        'fact_ids' => "La etiqueta del dato {$factId} se resuelve a otro(s) nodo(s) "
                            ."({$others}), no a este. Corrige la etiqueta del dato en lugar de "
                            .'forzar la vinculación.',
                    ]);
                }

                $manual = true;
            }

            $row = ReferenceFactGroupScope::firstOrCreate(
                ['reference_fact_id' => $factId, 'convenio_group_id' => $group->id],
                ['bound_by' => $adminId, 'bound_at' => now()],
            );
            $done[] = $factId;

            if (! $row->wasRecentlyCreated) {
                continue;
            }

            $why = 'dato vinculado al nodo "'.$group->label.'" ('.$group->code_normalized.') — '.$context;
            if ($manual) {
                // Keep the refusal reason next to the decision, so a later
                // reader can tell an asserted scope from a read one.
                $why .= '. El analizador NO resuelve esta etiqueta ('.$plan['kind'].'): '.$plan['reason'];
            }
            if (($note ?? '') !== '') {
                $why .= '. Nota: '.$note;
            }

            TagEvent::create([
                'entity_type' => 'reference_fact',
                'entity_id' => $factId,
                'facet' => $manual ? 'group_scope_manual' : 'group_scope',
                'old_value' => null,
                'new_value' => 'convenio_group:'.$group->id,
                'source' => 'admin_manual',
                'actor_id' => $adminId,
                'confidence' => null,
                'note' => $why,
            ]);
        }

        return $done;
    }

    /** Remove one fact↔node binding, with provenance. */
    public function unbind(Request $request, int $groupId, int $factId): JsonResponse
    {
        $group = ConvenioGroup::findOrFail($groupId);
        $adminId = $request->user()->id;

        $deleted = DB::transaction(function () use ($group, $factId, $adminId) {
            $n = ReferenceFactGroupScope::where('convenio_group_id', $group->id)
                ->where('reference_fact_id', $factId)->delete();

            if ($n > 0) {
                TagEvent::create([
                    'entity_type' => 'reference_fact',
                    'entity_id' => $factId,
                    'facet' => 'group_scope',
                    'old_value' => 'convenio_group:'.$group->id,
                    'new_value' => null,
                    'source' => 'admin_manual',
                    'actor_id' => $adminId,
                    'confidence' => null,
                    'note' => 'vinculación al nodo "'.$group->label.'" eliminada',
                ]);
            }

            return $n;
        });

        return response()->json(['status' => 'ok', 'removed' => $deleted]);
    }

    /** Queue a fresh AI proposal for this convenio. */
    public function propose(int $convenioId): JsonResponse
    {
        $convenio = Convenio::findOrFail($convenioId);
        ProposeConvenioGroups::dispatch($convenio->id);

        return response()->json([
            'status' => 'queued',
            'convenio_id' => $convenio->id,
            'note' => 'Los nodos propuestos llegarán como needs_review. Nada es comparable hasta que se apruebe.',
        ]);
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    /** @return \Illuminate\Support\Collection<int,ReferenceFact> */
    private function convenioFacts(int $convenioId)
    {
        return ReferenceFact::query()
            ->where('convenio_id', $convenioId)
            ->where('status', '!=', 'rejected')
            ->orderByRaw("(status = 'verified') DESC")
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  \Illuminate\Support\Collection<int,ConvenioGroup>  $children
     * @return array<string,mixed>
     */
    private function nodeRow(
        ConvenioGroup $node,
        $children,
        $memberships,
        $plans,
        $boundByFact,
    ): array {
        $row = $this->groupSummary($node);
        $mine = $memberships->get($node->id, collect());

        $row['categories'] = $mine->map(fn (ConvenioGroupCategory $m) => [
            'membership_id' => $m->id,
            'job_category_id' => $m->job_category_id,
            'name' => $m->jobCategory?->name,
            'group_code_evidence' => $m->jobCategory?->group_code,
            'status' => $m->status,
            'source' => $m->source,
        ])->values();

        $row['would_bind_facts'] = $plans
            ->filter(fn ($p) => $p['status'] === FactGroupBindingPlanner::STATUS_RESOLVED
                && in_array($node->id, $p['node_ids'], true))
            ->map(fn ($p) => [
                'fact_id' => $p['fact_id'],
                'fact_status' => $p['fact_status'],
                'group_label' => $p['group_label'],
                'value' => $p['value'],
                'kind' => $p['kind'],
                'bound' => $boundByFact->has($p['fact_id'])
                    && $boundByFact->get($p['fact_id'])->contains('convenio_group_id', $node->id),
            ])
            ->values();

        $row['children'] = $children
            ->map(fn (ConvenioGroup $c) => $this->nodeRow($c, collect(), $memberships, $plans, $boundByFact))
            ->values();

        return $row;
    }

    /** @return array<string,mixed> */
    private function groupSummary(ConvenioGroup $node): array
    {
        return [
            'id' => $node->id,
            'convenio_id' => $node->convenio_id,
            'parent_id' => $node->parent_id,
            'label' => $node->label,
            'code_normalized' => $node->code_normalized,
            'normalization_rule' => GroupCodeNormalizer::explain($node->label)['rule'],
            'source_excerpt' => $node->source_excerpt,
            'status' => $node->status,
            'source' => $node->source,
            'proposal_batch_id' => $node->proposal_batch_id,
            'approved_by' => $node->approvedBy?->full_name,
            'approved_at' => $node->approved_at?->toIso8601String(),
            'bound_fact_count' => ReferenceFactGroupScope::where('convenio_group_id', $node->id)->count(),
        ];
    }

    /**
     * @param  array<int,int>  $categoryIds
     */
    private function approveCategories(ConvenioGroup $group, array $categoryIds, int $adminId): void
    {
        foreach ($categoryIds as $categoryId) {
            $membership = ConvenioGroupCategory::where('convenio_group_id', $group->id)
                ->where('job_category_id', $categoryId)
                ->first();

            if ($membership === null) {
                throw ValidationException::withMessages([
                    'confirmed_category_ids' => "La categoría {$categoryId} no está propuesta para este nodo. "
                        .'Las categorías nunca se crean aquí.',
                ]);
            }

            // The Phase 1 partial unique index allows ONE approved membership per
            // category. Surface the clash as a validation error rather than a
            // 500: the reviewer needs to know which node already claims it.
            $claimed = ConvenioGroupCategory::where('job_category_id', $categoryId)
                ->where('status', ConvenioGroupCategory::STATUS_APPROVED)
                ->where('id', '!=', $membership->id)
                ->first();
            if ($claimed !== null) {
                throw ValidationException::withMessages([
                    'confirmed_category_ids' => "La categoría {$categoryId} ya está aprobada en el nodo "
                        .$claimed->convenio_group_id.'. Una categoría pertenece a un solo grupo.',
                ]);
            }

            $membership->update([
                'status' => ConvenioGroupCategory::STATUS_APPROVED,
                'approved_by' => $adminId,
                'approved_at' => now(),
            ]);

            TagEvent::create([
                'entity_type' => 'convenio_group',
                'entity_id' => $group->id,
                'facet' => 'group_category',
                'old_value' => null,
                'new_value' => 'job_category:'.$categoryId,
                'source' => 'admin_manual',
                'actor_id' => $adminId,
                'confidence' => null,
                'note' => 'categoría asignada al nodo "'.$group->label.'"',
            ]);
        }
    }

    private function logEvent(int $groupId, ?string $old, ?string $new, ?int $adminId, string $note): void
    {
        TagEvent::create([
            'entity_type' => 'convenio_group',
            'entity_id' => $groupId,
            'facet' => 'convenio_group',
            'old_value' => $old,
            'new_value' => $new,
            'source' => $adminId === null ? 'ai_agent' : 'admin_manual',
            'actor_id' => $adminId,
            'confidence' => null,
            'note' => $note,
        ]);
    }
}
