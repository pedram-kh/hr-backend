<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Convenio;
use App\Models\DocumentType;
use App\Models\Sector;
use App\Models\Territory;
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
            default => null,
        };

        if ($items === null) {
            return response()->json(['message' => "Unknown vocabulary '{$type}'."], 422);
        }

        return response()->json(['items' => $items]);
    }
}
