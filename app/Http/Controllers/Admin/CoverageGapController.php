<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\KnowledgeMap;
use Illuminate\Http\JsonResponse;

/**
 * Coverage-gap detection (spec §3) — the deploy.md §5 holes surfaced as
 * first-class, visible markers. Pure derivation over existing data (no scan,
 * no pipeline); see {@see KnowledgeMap::coverageGaps()}.
 */
class CoverageGapController extends Controller
{
    public function index(): JsonResponse
    {
        $gaps = KnowledgeMap::coverageGaps();

        return response()->json([
            'gaps' => $gaps,
            'counts' => [
                'unanswerable' => count($gaps['unanswerable']),
                'expired_no_successor' => count($gaps['expired_no_successor']),
                'suspected_mistag' => count($gaps['suspected_mistag']),
                'date_expired_active' => count($gaps['date_expired_active']),
            ],
        ]);
    }
}
