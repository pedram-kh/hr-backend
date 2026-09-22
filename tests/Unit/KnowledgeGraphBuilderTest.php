<?php

namespace Tests\Unit;

use App\Support\KnowledgeGraphBuilder as B;
use PHPUnit\Framework\TestCase;

/**
 * Sprint 11c (plan.md §C.3) — the edge builder is a pure function; every
 * honesty rule from spec §2 has its own fixture test here, with no DB and no
 * Laravel bootstrap (matches {@see GroupCodeNormalizerTest}'s posture).
 *
 * Fixtures below are hand-built, minimal worlds — not staging data — so each
 * test isolates exactly one rule. The counts these rules produce ON staging
 * are cross-checked separately, against `measure-graph.php`, at plan.md's
 * Step 3.
 */
class KnowledgeGraphBuilderTest extends TestCase
{
    // ---- fixture builders ---------------------------------------------------

    private function convenio(int $id, string $numero, ?int $territoryId, ?int $sectorId, string $name = 'Convenio'): array
    {
        return ['id' => $id, 'numero' => $numero, 'name' => $name, 'territory_id' => $territoryId, 'sector_id' => $sectorId];
    }

    private function document(int $id, string $uuid, ?int $convenioId, string $retrieval = 'active', string $tagging = 'verified', string $title = 'Doc'): array
    {
        return ['id' => $id, 'uuid' => $uuid, 'title' => $title, 'convenio_id' => $convenioId, 'retrieval_status' => $retrieval, 'tagging_status' => $tagging];
    }

    private function fact(int $id, string $uuid, int $convenioId, ?int $topicId, string $status = 'verified', string $source = 'admin_manual', ?int $sourceDocId = null, string $value = 'Fact'): array
    {
        return ['id' => $id, 'uuid' => $uuid, 'value' => $value, 'convenio_id' => $convenioId, 'topic_id' => $topicId, 'status' => $status, 'source' => $source, 'source_document_id' => $sourceDocId];
    }

    private function docTopic(int $documentId, int $topicId, string $source = 'admin_manual', ?int $verifiedBy = 1): array
    {
        return ['document_id' => $documentId, 'topic_id' => $topicId, 'source' => $source, 'verified_by' => $verifiedBy];
    }

    /** A minimal world with two of everything, so every hub clears the >=2 threshold by default. */
    private function baseWorld(array $overrides = []): array
    {
        return array_merge([
            'convenios' => [
                $this->convenio(1, '111', 10, 20, 'Convenio Uno'),
                $this->convenio(2, '222', 10, 20, 'Convenio Dos'),
            ],
            'documents' => [
                $this->document(1, 'doc-1', 1),
                $this->document(2, 'doc-2', 2),
            ],
            'reference_facts' => [
                $this->fact(1, 'fact-1', 1, 100),
                $this->fact(2, 'fact-2', 2, 100),
            ],
            'document_topics' => [
                $this->docTopic(1, 100),
                $this->docTopic(2, 100),
            ],
            'territories' => [['id' => 10, 'name' => 'Navarra']],
            'sectors' => [['id' => 20, 'name' => 'Deporte']],
            'topics' => [['id' => 100, 'name' => 'Jornada']],
        ], $overrides);
    }

    private function nodesById(array $result): array
    {
        $out = [];
        foreach ($result['nodes'] as $n) {
            $out[$n['id']] = $n;
        }

        return $out;
    }

    // ---- 1. the six edge types, from fixtures --------------------------------

    public function test_builds_the_six_edge_types_from_fixtures(): void
    {
        $result = B::build($this->baseWorld());

        $kinds = array_values(array_unique(array_column($result['edges'], 'kind')));
        $expected = B::EDGE_KINDS;
        sort($kinds);
        sort($expected);
        $this->assertSame($expected, $kinds, 'expected exactly the six documented edge kinds, all present in this world');

        $this->assertContains(['source' => 'doc:doc-1', 'target' => 'c:1', 'kind' => 'document_convenio', 'provenance' => 'system'], $result['edges']);
        $this->assertContains(['source' => 'doc:doc-1', 'target' => 'tp:100', 'kind' => 'document_topic', 'provenance' => 'system'], $result['edges']);
        $this->assertContains(['source' => 'fact:fact-1', 'target' => 'c:1', 'kind' => 'fact_convenio', 'provenance' => 'system'], $result['edges']);
        $this->assertContains(['source' => 'fact:fact-1', 'target' => 'tp:100', 'kind' => 'fact_topic', 'provenance' => 'system'], $result['edges']);
        $this->assertContains(['source' => 'c:1', 'target' => 't:10', 'kind' => 'convenio_territory', 'provenance' => 'system'], $result['edges']);
        $this->assertContains(['source' => 'c:1', 'target' => 's:20', 'kind' => 'convenio_sector', 'provenance' => 'system'], $result['edges']);
    }

    // ---- 2. no other edge kind can ever appear -------------------------------

    public function test_no_edge_kind_outside_the_allowlist_can_be_emitted(): void
    {
        $result = B::build($this->baseWorld());
        $this->assertNotEmpty($result['edges']);
        foreach ($result['edges'] as $edge) {
            $this->assertContains($edge['kind'], B::EDGE_KINDS, "unexpected edge kind '{$edge['kind']}'");
        }
    }

    // ---- 3. shared source document (the #105/#106 shape) ---------------------

    public function test_shared_source_document_creates_no_fact_to_document_edge(): void
    {
        $world = $this->baseWorld([
            // The shared source itself: no convenio, no topic tags — an orphan.
            'documents' => [
                $this->document(1, 'doc-1', 1),
                $this->document(2, 'doc-2', 2),
                $this->document(105, 'doc-shared', null, 'active', 'verified', 'Shared reference source'),
            ],
            'reference_facts' => [
                $this->fact(1, 'fact-1', 1, 100, sourceDocId: 105),
                $this->fact(2, 'fact-2', 2, 100, sourceDocId: 105),
            ],
        ]);
        $result = B::build($world);
        $nodes = $this->nodesById($result);

        $this->assertArrayNotHasKey('doc:doc-shared', $nodes, 'the shared source has no convenio and no topic tag — it is an orphan and must not draw');
        foreach ($result['edges'] as $edge) {
            $this->assertNotSame('doc:doc-shared', $edge['source']);
            $this->assertNotSame('doc:doc-shared', $edge['target']);
        }
        // The provenance is surfaced on the FACT node instead of drawn as an edge.
        $this->assertSame(['id' => 105, 'title' => 'Shared reference source'], $nodes['fact:fact-1']['source_document']);
        $this->assertSame(1, $result['counts']['hidden']['documents_orphan']);
    }

    // ---- 4. document<->document columns are never read into an edge ----------

    public function test_no_document_to_document_edge_from_predecessor_or_derived_from(): void
    {
        $world = $this->baseWorld();
        // Extra columns a real query might carry — the builder must ignore them
        // entirely; there is no code path that could turn them into an edge.
        $world['documents'][0]['predecessor_document_id'] = 999;
        $world['documents'][0]['derived_from_document_id'] = 998;

        $result = B::build($world);
        foreach ($result['edges'] as $edge) {
            $this->assertStringStartsNotWith('doc:', $edge['target'], 'no edge may target a document node');
        }
    }

    // ---- 5/6. hub sparsity — the >=2 boundary from both sides ----------------

    public function test_hub_with_one_attachment_is_folded_not_drawn(): void
    {
        $world = $this->baseWorld([
            'convenios' => [$this->convenio(1, '111', 10, 20, 'Convenio Solo')],
            'documents' => [$this->document(1, 'doc-1', 1)],
            'reference_facts' => [],
            'document_topics' => [],
        ]);
        $result = B::build($world);
        $nodes = $this->nodesById($result);

        $this->assertArrayNotHasKey('t:10', $nodes, 'a territory with exactly one convenio must fold, not draw');
        $this->assertArrayNotHasKey('s:20', $nodes, 'a sector with exactly one convenio must fold, not draw');
        $this->assertSame('Navarra', $nodes['c:1']['folded']['territory']);
        $this->assertSame('Deporte', $nodes['c:1']['folded']['sector']);
        $this->assertSame(1, $result['counts']['hidden']['territories_not_drawn']);
        $this->assertSame(1, $result['counts']['hidden']['sectors_not_drawn']);
    }

    public function test_hub_with_two_attachments_is_drawn(): void
    {
        $result = B::build($this->baseWorld()); // two convenios, same territory+sector
        $nodes = $this->nodesById($result);

        $this->assertArrayHasKey('t:10', $nodes);
        $this->assertSame(2, $nodes['t:10']['counts']['convenios']);
        $this->assertArrayHasKey('s:20', $nodes);
        $this->assertSame(2, $nodes['s:20']['counts']['convenios']);
        $this->assertNull($nodes['c:1']['folded']['territory'], 'a drawn hub is never also folded onto its convenio');
        $this->assertNull($nodes['c:1']['folded']['sector']);
        $this->assertSame(0, $result['counts']['hidden']['territories_not_drawn']);
    }

    // ---- 7. an unreachable document is dropped, and counted ------------------

    public function test_orphan_document_is_dropped_and_counted(): void
    {
        $world = $this->baseWorld([
            'documents' => [
                $this->document(1, 'doc-1', 1),
                $this->document(2, 'doc-2', 2),
                $this->document(3, 'doc-orphan', null), // no convenio, no topic tag added below
            ],
        ]);
        $result = B::build($world);
        $nodes = $this->nodesById($result);

        $this->assertArrayNotHasKey('doc:doc-orphan', $nodes);
        $this->assertSame(1, $result['counts']['hidden']['documents_orphan']);
    }

    public function test_document_with_only_a_topic_tag_to_a_drawn_hub_still_draws(): void
    {
        $world = $this->baseWorld([
            'documents' => [
                $this->document(1, 'doc-1', 1),
                $this->document(2, 'doc-2', 2),
                $this->document(3, 'doc-unbound', null), // no convenio, but tagged below
            ],
            'document_topics' => [
                $this->docTopic(1, 100),
                $this->docTopic(2, 100),
                $this->docTopic(3, 100), // reaches the drawn topic hub
            ],
        ]);
        $result = B::build($world);
        $nodes = $this->nodesById($result);

        $this->assertArrayHasKey('doc:doc-unbound', $nodes, 'a topic edge to a drawn hub is enough to keep a convenio-less document');
        $this->assertSame(0, $result['counts']['hidden']['documents_orphan']);
        $this->assertContains(
            ['source' => 'doc:doc-unbound', 'target' => 'tp:100', 'kind' => 'document_topic', 'provenance' => 'system'],
            $result['edges'],
        );
    }

    // ---- 8. a rejected fact is dropped, and counted --------------------------

    public function test_rejected_fact_is_dropped_and_counted(): void
    {
        $world = $this->baseWorld([
            'reference_facts' => [
                $this->fact(1, 'fact-1', 1, 100),
                $this->fact(2, 'fact-2', 2, 100, status: 'rejected'),
            ],
        ]);
        $result = B::build($world);
        $nodes = $this->nodesById($result);

        $this->assertArrayNotHasKey('fact:fact-2', $nodes);
        $this->assertSame(1, $result['counts']['hidden']['facts_rejected']);
        foreach ($result['edges'] as $edge) {
            $this->assertNotSame('fact:fact-2', $edge['source']);
        }
    }

    // ---- 9. the dev-fixture convenio is never real data ----------------------

    public function test_dev_fixture_convenio_is_excluded(): void
    {
        $world = $this->baseWorld([
            'convenios' => [
                $this->convenio(1, '111', 10, 20, 'Convenio Uno'),
                $this->convenio(2, '222', 10, 20, 'Convenio Dos'),
                $this->convenio(28, 'DEV-FIXTURE-0001', 10, 20, 'DEV FIXTURE'),
            ],
        ]);
        $result = B::build($world);
        $nodes = $this->nodesById($result);

        $this->assertArrayNotHasKey('c:28', $nodes);
        $this->assertSame(1, $result['counts']['hidden']['convenios_excluded']);
        // Its presence must not itself inflate the territory/sector hub degree.
        $this->assertSame(2, $nodes['t:10']['counts']['convenios']);
    }

    // ---- 10. document state precedence ---------------------------------------

    public function test_state_precedence_is_unverified_ai_then_historical_then_draft_then_active(): void
    {
        // The real shape: historical + still under_review + an unverified AI facet.
        $this->assertSame('unverified_ai', B::documentState('historical', 'under_review', hasUnverifiedAiFacet: true));
        $this->assertSame('historical', B::documentState('historical', 'verified', hasUnverifiedAiFacet: false));
        $this->assertSame('draft', B::documentState('draft', 'verified', hasUnverifiedAiFacet: false));
        $this->assertSame('active', B::documentState('active', 'verified', hasUnverifiedAiFacet: false));
    }

    /**
     * The guard that matters: 3 of the 8 documents on real data carrying an
     * unverified AI facet are THEMSELVES already `tagging_status = verified`
     * (docs 18, 50, 85 — plan.md §D.2). Painting a verified document fuchsia
     * would be exactly the "bleed onto confirmed content" ADR-0020 forbids.
     */
    public function test_a_verified_document_never_reads_as_unverified_ai_even_with_a_stray_unverified_facet_row(): void
    {
        $this->assertSame('active', B::documentState('active', 'verified', hasUnverifiedAiFacet: true));
        $this->assertSame('historical', B::documentState('historical', 'verified', hasUnverifiedAiFacet: true));
    }

    public function test_document_state_end_to_end_from_document_topics_rows(): void
    {
        $world = $this->baseWorld([
            'documents' => [
                $this->document(1, 'doc-1', 1, retrieval: 'historical', tagging: 'under_review'),
                $this->document(2, 'doc-2', 2),
            ],
            'document_topics' => [
                $this->docTopic(1, 100, source: 'ai_agent', verifiedBy: null),
                $this->docTopic(2, 100),
            ],
        ]);
        $result = B::build($world);
        $nodes = $this->nodesById($result);

        $this->assertSame('unverified_ai', $nodes['doc:doc-1']['state']);
        // The edge itself carries the AI provenance signal too (plan.md §D.2).
        $edge = current(array_filter(
            $result['edges'],
            fn ($e) => $e['source'] === 'doc:doc-1' && $e['kind'] === 'document_topic',
        ));
        $this->assertSame('unverified_ai', $edge['provenance']);
    }

    // ---- 11. fact state — verify reverts the AI signal (ADR-0020) ------------

    public function test_verified_ai_fact_is_not_unverified_ai(): void
    {
        // 131 of 154 real facts are exactly this shape: ai_agent AND verified.
        $this->assertSame('verified', B::factState('verified', 'ai_agent'));
        $this->assertSame('unverified_ai', B::factState('needs_review', 'ai_agent'));
        // Parity with HierarchyController::factLeafNodes()'s `is_ai_proposed`
        // (`source === 'ai_agent' && status === 'needs_review'`) — deliberate,
        // not incidental: the two screens must agree on what "AI-proposed" means.
        $this->assertSame('verified', B::factState('needs_review', 'admin_manual'));
    }

    // ---- 12. deterministic, input-order-independent output -------------------

    public function test_output_order_is_deterministic_and_input_order_independent(): void
    {
        $world = $this->baseWorld([
            'convenios' => [
                $this->convenio(2, '222', 10, 20, 'Convenio Dos'),
                $this->convenio(1, '111', 10, 20, 'Convenio Uno'),
            ],
            'documents' => [
                $this->document(2, 'doc-2', 2),
                $this->document(1, 'doc-1', 1),
            ],
            'reference_facts' => [
                $this->fact(2, 'fact-2', 2, 100),
                $this->fact(1, 'fact-1', 1, 100),
            ],
            'document_topics' => [
                $this->docTopic(2, 100),
                $this->docTopic(1, 100),
            ],
        ]);

        $a = B::build($this->baseWorld());
        $b = B::build($world);

        $this->assertSame(array_column($a['nodes'], 'id'), array_column($b['nodes'], 'id'));
        $this->assertSame($a['edges'], $b['edges']);
        // And the order groups by type (convenio, document, fact, sector, territory, topic).
        $ids = array_column($a['nodes'], 'id');
        $this->assertSame(['c:1', 'c:2', 'doc:doc-1', 'doc:doc-2', 'fact:fact-1', 'fact:fact-2', 's:20', 't:10', 'tp:100'], $ids);
    }

    // ---- counts sanity (feeds Step 3's staging cross-check) -------------------

    public function test_counts_totals_match_node_and_edge_array_lengths(): void
    {
        $result = B::build($this->baseWorld());
        $this->assertSame(count($result['nodes']), $result['counts']['nodes']);
        $this->assertSame(count($result['edges']), $result['counts']['edges']);
    }
}
