<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Convenio;
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
}
