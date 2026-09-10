<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\CorpusCoverageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

/**
 * Sprint 8, Step 2/7 (plan.md §5, §9) — the Cobertura screen's read API.
 * Gated on `analytics.view` OR `knowledge.edit` in the route group (plan.md
 * §9/§12 resolved q7 — `knowledge_editor` reaches coverage via its EXISTING
 * ability, without gaining Analítica/quality review). Every number here
 * calls `CorpusCoverageService`, the SAME service `corpus:coverage` and
 * `coverage:snapshot` call — the screen and the export share one query
 * (§5.6's hard constraint), true by construction, not by discipline.
 */
class CoverageController extends Controller
{
    /** The grid + full-gap ranked list + no-registry rows — one call, one query. */
    public function gaps(Request $request, CorpusCoverageService $service): JsonResponse
    {
        $asOf = $request->query('as_of') ? Carbon::parse($request->query('as_of')) : Carbon::today();
        $grid = $service->grid($asOf);

        return response()->json([
            'as_of' => $asOf->toDateString(),
            'grid' => $grid,
            'full_gap_convenios' => $service->fullGapConvenios($grid),
            'no_registry_rows' => $service->noRegistryConvenioRows(),
        ]);
    }

    /** The exact `corpus:coverage --print` markdown, as a download (byte-identical to the CLI export, §5.6). */
    public function export(Request $request, CorpusCoverageService $service): Response
    {
        $asOf = $request->query('as_of') ? Carbon::parse($request->query('as_of')) : Carbon::today();
        $grid = $service->grid($asOf);
        $markdown = $service->toMarkdown($grid, $service->noRegistryConvenioRows(), $asOf);

        return response($markdown, 200, [
            'Content-Type' => 'text/markdown; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="corpus-coverage-'.$asOf->toDateString().'.md"',
        ]);
    }

    /** Gaps-closed trend (plan.md §5.5) — reads `coverage_snapshots`' own history. */
    public function trend(Request $request, CorpusCoverageService $service): JsonResponse
    {
        $limit = (int) $request->query('limit', 30);

        return response()->json(['trend' => $service->snapshotTrend($limit)]);
    }
}
