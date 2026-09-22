<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\KnowledgeGraphBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Sprint 11c (plan.md §A.5) — the knowledge graph read. Same gate as the rest
 * of Map (admin group, no extra ability — see routes/api.php's comment at
 * this route). This controller does ONLY the querying: every honesty rule
 * (hub sparsity, dev-fixture exclusion, rejected-fact exclusion, orphan-
 * document exclusion, state precedence) lives in {@see KnowledgeGraphBuilder},
 * a pure function proven by `tests/Unit/KnowledgeGraphBuilderTest.php`.
 *
 * Column selection is deliberately narrow — no chunk text, no employee/PII
 * columns, nothing beyond what the builder's documented input shape needs
 * (spec §3, tested by `Sprint11cKnowledgeGraphTest::test_response_carries_no_
 * chunk_text_or_employee_data`).
 */
class KnowledgeGraphController extends Controller
{
    public function index(): JsonResponse
    {
        $rows = [
            'convenios' => DB::table('convenios')
                ->select('id', 'numero', 'name', 'territory_id', 'sector_id')
                ->get()->map(fn ($r) => (array) $r)->all(),
            'documents' => DB::table('documents')
                ->select('id', 'uuid', 'title', 'convenio_id', 'retrieval_status', 'tagging_status')
                ->get()->map(fn ($r) => (array) $r)->all(),
            'reference_facts' => DB::table('reference_facts')
                ->select('id', 'uuid', 'value', 'convenio_id', 'topic_id', 'status', 'source', 'source_document_id')
                ->get()->map(fn ($r) => (array) $r)->all(),
            'document_topics' => DB::table('document_topics')
                ->select('document_id', 'topic_id', 'source', 'verified_by')
                ->get()->map(fn ($r) => (array) $r)->all(),
            'territories' => DB::table('territories')
                ->select('id', 'name')
                ->get()->map(fn ($r) => (array) $r)->all(),
            'sectors' => DB::table('sectors')
                ->select('id', 'name')
                ->get()->map(fn ($r) => (array) $r)->all(),
            'topics' => DB::table('topics')
                ->select('id', 'name')
                ->get()->map(fn ($r) => (array) $r)->all(),
        ];

        $graph = KnowledgeGraphBuilder::build($rows);

        return response()->json([
            'generated_at' => now()->toIso8601String(),
            'counts' => $graph['counts'],
            'nodes' => $graph['nodes'],
            'edges' => $graph['edges'],
        ]);
    }
}
