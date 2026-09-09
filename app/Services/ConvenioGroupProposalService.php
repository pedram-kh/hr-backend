<?php

namespace App\Services;

use App\Models\AnswerModelSetting;
use App\Models\Convenio;
use App\Models\ConvenioGroup;
use App\Models\ConvenioGroupCategory;
use App\Models\ReferenceFact;
use App\Models\TagEvent;
use App\Support\GroupCodeNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Sprint 7f (ADR-0028) — the group-structure proposer, persist side.
 *
 * The 7b-2 `ReferenceFactProposalService` pattern, applied to structure instead
 * of facts, and with the same three properties that make an AI writer safe here:
 *
 *  1. EVERY node lands `ai_agent` / `needs_review`. Phase 3's matcher only ever
 *     joins `status = 'approved'`, so a proposal is not merely unverified — it is
 *     invisible to the answer path. Nothing exists until a human approves it.
 *  2. NO CATEGORY IS EVER MINTED (ADR-0011). hr-ai validates ids against the
 *     closed set and this service validates them AGAIN against the convenio's
 *     own categories, because a category is only valid for its own convenio.
 *  3. NORMALIZATION HAPPENS HERE, ONCE. The model returns the label as printed;
 *     `GroupCodeNormalizer` derives `code_normalized`. The model never sees or
 *     produces a comparison key, so there is exactly one implementation of the
 *     thing Phase 3 compares.
 *
 * Re-running is idempotent on `(convenio_id, parent_id, code_normalized)`: a
 * second pass updates the pending proposal in place rather than stacking
 * duplicates. It DOES NOT touch a node a human already approved or rejected —
 * re-proposing must never quietly reopen a decision, and it must never silently
 * un-approve structure the answer path is already using.
 */
class ConvenioGroupProposalService
{
    public function __construct(private ExtractionClient $ai) {}

    /**
     * @return array<string,mixed>
     */
    public function propose(Convenio $convenio): array
    {
        $settings = AnswerModelSetting::current();
        if (! $settings->isConfigured()) {
            return ['status' => 'skipped', 'reason' => 'answer_model_not_configured'];
        }

        $pagesText = $this->convenioText($convenio);
        if (trim($pagesText) === '') {
            return ['status' => 'skipped', 'reason' => 'no_convenio_text'];
        }

        $providerConfig = [
            'provider' => config('services.hr_ai.answer_provider', 'claude'),
            'model' => config('services.hr_ai.answer_model'),
            'endpoint' => config('services.hr_ai.answer_endpoint'),
        ];

        $key = $settings->decryptKey();
        $result = $this->ai->proposeGroups(
            $this->buildConvenioPayload($convenio),
            $pagesText,
            $this->observedGroupLabels($convenio),
            $key,
            $providerConfig,
        );
        unset($key);

        if (isset($result['error'])) {
            Log::warning('group proposal: provider failure (convenio left without a structure)', [
                'convenio_id' => $convenio->id,
                'error' => $result['error'],
            ]);

            return ['status' => 'error', 'reason' => $result['error']];
        }

        return $this->persist($convenio, $result['groups'] ?? [], $result['trace_fragment'] ?? []);
    }

    /**
     * The convenio's own text, in page order, from the documents bound to it.
     * A convenio's group structure lives in ONE article, but which one varies,
     * so the model gets the whole thing rather than a guessed slice.
     */
    private function convenioText(Convenio $convenio): string
    {
        return DB::table('document_pages')
            ->join('documents', 'documents.id', '=', 'document_pages.document_id')
            ->where('documents.convenio_id', $convenio->id)
            ->orderBy('documents.id')
            ->orderBy('document_pages.page_number')
            ->pluck('document_pages.text')
            ->filter(fn ($t) => trim((string) $t) !== '')
            ->implode("\n\n");
    }

    /**
     * `group_label`s that VERIFIED facts of this convenio already use — the
     * checklist described in the prompt. Verified only: an unverified label is
     * itself an unreviewed AI guess, and feeding it back as a requirement would
     * let one proposal justify the next.
     *
     * @return list<string>
     */
    private function observedGroupLabels(Convenio $convenio): array
    {
        return ReferenceFact::query()
            ->where('convenio_id', $convenio->id)
            ->where('status', 'verified')
            ->whereNotNull('group_label')
            ->pluck('group_label')
            ->map(fn ($l) => trim((string) $l))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<string,mixed>
     */
    private function buildConvenioPayload(Convenio $convenio): array
    {
        $convenio->loadMissing(['territory', 'sector', 'jobCategories']);

        return [
            'id' => $convenio->id,
            'name' => (string) $convenio->name,
            'numero' => $convenio->numero,
            'aliases' => array_values(array_filter((array) ($convenio->aliases ?? []))),
            'territory_name' => (string) ($convenio->territory?->name ?? ''),
            'territory_aliases' => array_values(array_filter((array) ($convenio->territory?->aliases ?? []))),
            'sector_name' => (string) ($convenio->sector?->name ?? ''),
            'sector_aliases' => array_values(array_filter((array) ($convenio->sector?->aliases ?? []))),
            'job_categories' => $convenio->jobCategories
                ->map(fn ($jc) => [
                    'id' => $jc->id,
                    'name' => (string) $jc->name,
                    'group_code' => $jc->group_code,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $groups
     * @param  array<string,mixed>  $trace
     * @return array<string,mixed>
     */
    private function persist(Convenio $convenio, array $groups, array $trace): array
    {
        $batchId = (string) Str::uuid();
        $validCategoryIds = $convenio->jobCategories->pluck('id')->all();

        $created = 0;
        $updated = 0;
        $skippedLocked = 0;
        $skippedUnnormalizable = 0;
        $droppedForeignCategories = 0;
        $categoriesProposed = 0;

        DB::transaction(function () use (
            $convenio, $groups, $batchId, $validCategoryIds,
            &$created, &$updated, &$skippedLocked, &$skippedUnnormalizable,
            &$droppedForeignCategories, &$categoriesProposed
        ) {
            // Roots first: a sub-area needs its parent's id, and hr-ai has
            // already guaranteed every surviving sub-area names a proposed root.
            $rootsByKey = [];
            foreach ($this->partition($groups, root: true) as $node) {
                $persisted = $this->upsertNode($convenio, $node, null, $batchId, $validCategoryIds,
                    $created, $updated, $skippedLocked, $skippedUnnormalizable,
                    $droppedForeignCategories, $categoriesProposed);
                if ($persisted !== null) {
                    $rootsByKey[$this->labelKey($node['code_label'] ?? '')] = $persisted;
                }
            }

            foreach ($this->partition($groups, root: false) as $node) {
                $parent = $rootsByKey[$this->labelKey($node['parent_code_label'] ?? '')] ?? null;
                if ($parent === null) {
                    // Its root was refused above (unnormalizable, or locked by a
                    // human decision). Dropping the child is the only safe move:
                    // re-parenting it would invent a placement.
                    $skippedLocked++;

                    continue;
                }
                $this->upsertNode($convenio, $node, $parent, $batchId, $validCategoryIds,
                    $created, $updated, $skippedLocked, $skippedUnnormalizable,
                    $droppedForeignCategories, $categoriesProposed);
            }
        });

        Log::info('group proposal persisted', [
            'convenio_id' => $convenio->id,
            'batch_id' => $batchId,
            'created' => $created,
            'updated' => $updated,
        ]);

        return [
            'status' => 'ok',
            'batch_id' => $batchId,
            'convenio_id' => $convenio->id,
            'proposed' => count($groups),
            'created' => $created,
            'updated' => $updated,
            'skipped_locked' => $skippedLocked,
            'skipped_unnormalizable' => $skippedUnnormalizable,
            'dropped_foreign_categories' => $droppedForeignCategories,
            'categories_proposed' => $categoriesProposed,
            'trace_fragment' => $trace,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $groups
     * @return list<array<string,mixed>>
     */
    private function partition(array $groups, bool $root): array
    {
        return array_values(array_filter(
            $groups,
            fn ($n) => is_array($n) && (($n['parent_code_label'] ?? null) === null) === $root,
        ));
    }

    /**
     * @param  array<string,mixed>  $node
     * @param  list<int>  $validCategoryIds
     */
    private function upsertNode(
        Convenio $convenio,
        array $node,
        ?ConvenioGroup $parent,
        string $batchId,
        array $validCategoryIds,
        int &$created,
        int &$updated,
        int &$skippedLocked,
        int &$skippedUnnormalizable,
        int &$droppedForeignCategories,
        int &$categoriesProposed,
    ): ?ConvenioGroup {
        $label = trim((string) ($node['code_label'] ?? ''));
        $code = GroupCodeNormalizer::normalize($label);

        if ($code === '') {
            // Rule 6 of the normalizer: a label that reduces to nothing is
            // refused, not invented. "Grupo" on its own has no comparison key,
            // and Phase 3 compares keys.
            $skippedUnnormalizable++;

            return null;
        }

        $existing = ConvenioGroup::query()
            ->where('convenio_id', $convenio->id)
            ->where('parent_id', $parent?->id)
            ->where('code_normalized', $code)
            ->first();

        if ($existing !== null && $existing->status !== ConvenioGroup::STATUS_NEEDS_REVIEW) {
            // A human has already approved or rejected this node. Re-proposing
            // must never reopen that: an approved node may already be bound to
            // facts and assigned to employees, and a rejected one was rejected
            // on purpose.
            $skippedLocked++;

            return $existing->status === ConvenioGroup::STATUS_APPROVED ? $existing : null;
        }

        $isNew = $existing === null;
        $group = $existing ?? new ConvenioGroup;
        $group->fill([
            'convenio_id' => $convenio->id,
            'parent_id' => $parent?->id,
            'code_normalized' => $code,
            'label' => $label,
            'source_excerpt' => $this->excerpt($node),
            'status' => ConvenioGroup::STATUS_NEEDS_REVIEW,
            'source' => ConvenioGroup::SOURCE_AI,
            'proposal_batch_id' => $batchId,
        ]);
        $group->save();

        $isNew ? $created++ : $updated++;

        $this->logEvent(
            $group->id,
            null,
            ConvenioGroup::STATUS_NEEDS_REVIEW,
            $isNew
                ? 'AI propuso el nodo "'.$label.'" → '.$code.' (inerte; pendiente de aprobación humana)'
                : 'AI re-propuso el nodo "'.$label.'" → '.$code.' (upsert sobre convenio+padre+código)',
            is_numeric($node['confidence'] ?? null) ? (float) $node['confidence'] : null,
        );

        $this->syncProposedCategories(
            $group,
            $node['job_category_ids'] ?? [],
            $validCategoryIds,
            $droppedForeignCategories,
            $categoriesProposed,
        );

        return $group;
    }

    /**
     * Keep the model's excerpt AND its uncertainty together on the node, because
     * the reviewer needs both in one place: the citation is what makes a node
     * checkable, and the flag is what tells them to look harder.
     *
     * @param  array<string,mixed>  $node
     */
    private function excerpt(array $node): ?string
    {
        $parts = [];
        if (($locator = trim((string) ($node['source_locator'] ?? ''))) !== '') {
            $parts[] = '['.$locator.']';
        }
        if (($excerpt = trim((string) ($node['source_excerpt'] ?? ''))) !== '') {
            $parts[] = $excerpt;
        }
        $uncertainty = $node['uncertainty'] ?? null;
        if (is_array($uncertainty) && trim((string) ($uncertainty['reason'] ?? '')) !== '') {
            $parts[] = "\n⚠ ".trim((string) ($uncertainty['field'] ?? 'incierto')).': '
                .trim((string) $uncertainty['reason']);
        }

        return $parts === [] ? null : implode(' ', $parts);
    }

    /**
     * Category memberships are proposals too — `needs_review` rows in the pivot,
     * never approved ones, so the Phase 1 partial unique index (one APPROVED
     * membership per category) is never contended by a proposal.
     *
     * @param  array<int,mixed>  $proposedIds
     * @param  list<int>  $validCategoryIds
     */
    private function syncProposedCategories(
        ConvenioGroup $group,
        array $proposedIds,
        array $validCategoryIds,
        int &$droppedForeignCategories,
        int &$categoriesProposed,
    ): void {
        foreach ($proposedIds as $categoryId) {
            if (! is_int($categoryId) || ! in_array($categoryId, $validCategoryIds, true)) {
                // Defence in depth: hr-ai already validated against the closed
                // set, but a category is only valid for its OWN convenio and
                // this is the layer that owns that fact.
                $droppedForeignCategories++;

                continue;
            }

            $membership = ConvenioGroupCategory::query()
                ->where('convenio_group_id', $group->id)
                ->where('job_category_id', $categoryId)
                ->first();

            if ($membership !== null) {
                continue; // already proposed, or already decided by a human
            }

            ConvenioGroupCategory::create([
                'convenio_group_id' => $group->id,
                'job_category_id' => $categoryId,
                'status' => ConvenioGroupCategory::STATUS_NEEDS_REVIEW,
                'source' => ConvenioGroupCategory::SOURCE_AI,
            ]);
            $categoriesProposed++;
        }
    }

    /** The dedupe key for matching a sub-area to the root the model named. */
    private function labelKey(string $label): string
    {
        return preg_replace('/[^a-z0-9]+/', '', mb_strtolower(trim($label))) ?? $label;
    }

    private function logEvent(int $groupId, ?string $old, ?string $new, string $note, ?float $confidence = null): void
    {
        TagEvent::create([
            'entity_type' => 'convenio_group',
            'entity_id' => $groupId,
            'facet' => 'convenio_group',
            'old_value' => $old,
            'new_value' => $new,
            'source' => 'ai_agent', // the lane lights here for structure (7f)
            'actor_id' => null,
            'confidence' => $confidence,
            'note' => $note,
        ]);
    }
}
