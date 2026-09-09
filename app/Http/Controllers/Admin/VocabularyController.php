<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Convenio;
use App\Models\ConvenioGroup;
use App\Models\ConvenioGroupCategory;
use App\Models\ConvenioJobCategory;
use App\Models\DocumentType;
use App\Models\Sector;
use App\Models\Territory;
use App\Models\Topic;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only controlled-vocabulary options for the admin re-assign dropdowns.
 * The admin can only pick existing values — never free text (ADR-0002).
 */
class VocabularyController extends Controller
{
    public function index(string $type): JsonResponse
    {
        $items = match ($type) {
            'territories' => Territory::orderBy('name')->get(['id', 'code', 'name', 'level']),
            'sectors' => Sector::orderBy('name')->get(['id', 'name']),
            'convenios' => Convenio::with(['territory:id,name', 'sector:id,name'])
                ->orderBy('numero')
                ->get(['id', 'numero', 'name', 'territory_id', 'sector_id']),
            'document_types' => DocumentType::orderBy('name')->get(['id', 'code', 'name']),
            // Only APPROVED topics are pickable — the UI tags into existing
            // vocabulary, it never creates/approves topics (ADR-0011; that stays
            // in the deliberate path, Sprint 7).
            'topics' => Topic::where('status', 'approved')->orderBy('name')->get(['id', 'name']),
            default => null,
        };

        if ($items === null) {
            return response()->json(['message' => "Unknown vocabulary '{$type}'."], 422);
        }

        return response()->json(['items' => $items]);
    }

    /**
     * Job categories scoped to ONE convenio — the directory FK picker for an
     * employee's job_category_id (Sprint 5). Categories are per-convenio (no
     * global list), so the picker must filter by the chosen convenio. Existing
     * vocabulary only — never created here (growth stays in salary:import, ADR-0011).
     */
    public function jobCategories(Request $request): JsonResponse
    {
        $data = $request->validate([
            'convenio_id' => ['required', 'integer', 'exists:convenios,id'],
        ]);

        $items = ConvenioJobCategory::where('convenio_id', $data['convenio_id'])
            ->orderBy('name')
            ->get(['id', 'name', 'group_code']);

        return response()->json(['items' => $items]);
    }

    /**
     * Sprint 7f (ADR-0028) — the group/sub-area picker for an employee's
     * `convenio_group_id`, scoped to ONE convenio and to APPROVED nodes only.
     *
     * A proposed node is inert everywhere, including here: offering one would let
     * an admin bind an employee to structure no human has approved, which is the
     * gate this sprint is built around. Returned flat, parent-first, with
     * `parent_id` and `depth` so the client renders a two-level indented select
     * without needing to know the tree rules.
     *
     * `suggested_group_id` is the convenience half of §3.6: where the employee's
     * job category maps to exactly one approved node, the form pre-fills that
     * node and labels it "sugerido a partir de la categoría — confirma". It is a
     * SUGGESTION and nothing more — nothing is written until an admin saves, and
     * the matcher never reads category membership. Where the mapping is absent or
     * ambiguous it is null, which per the §3.4 census is most of the corpus.
     */
    public function groups(Request $request): JsonResponse
    {
        $data = $request->validate([
            'convenio_id' => ['required', 'integer', 'exists:convenios,id'],
            'job_category_id' => ['nullable', 'integer', 'exists:convenio_job_categories,id'],
        ]);

        $nodes = ConvenioGroup::query()
            ->approved()
            ->where('convenio_id', $data['convenio_id'])
            ->orderBy('code_normalized')
            ->get(['id', 'parent_id', 'code_normalized', 'label']);

        // Parent, then its children, then the next parent. Ordering here rather
        // than in the client keeps "what a two-level tree looks like" in one place.
        $byParent = $nodes->groupBy('parent_id');
        $items = [];
        foreach ($byParent->get('', $byParent->get(null, collect())) as $root) {
            $items[] = ['id' => $root->id, 'parent_id' => null, 'depth' => 0,
                'code_normalized' => $root->code_normalized, 'label' => $root->label,
                'path_label' => $root->label];

            foreach ($byParent->get((string) $root->id, collect()) as $child) {
                $items[] = ['id' => $child->id, 'parent_id' => $root->id, 'depth' => 1,
                    'code_normalized' => $child->code_normalized, 'label' => $child->label,
                    'path_label' => $root->label.' › '.$child->label];
            }
        }

        $suggested = null;
        if (! empty($data['job_category_id'])) {
            $approvedForCategory = ConvenioGroupCategory::query()
                ->where('job_category_id', $data['job_category_id'])
                ->where('status', ConvenioGroup::STATUS_APPROVED)
                ->pluck('convenio_group_id');

            // Exactly one, or nothing. A partial unique index makes >1 unreachable,
            // but reading it as "only when unambiguous" keeps the rule visible.
            $suggested = $approvedForCategory->count() === 1 ? (int) $approvedForCategory->first() : null;
        }

        return response()->json(['items' => $items, 'suggested_group_id' => $suggested]);
    }
}
