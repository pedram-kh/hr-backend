<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Convenio;
use App\Models\DocumentType;
use App\Models\Sector;
use App\Models\Territory;
use App\Models\Topic;
use Illuminate\Http\JsonResponse;

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
}
