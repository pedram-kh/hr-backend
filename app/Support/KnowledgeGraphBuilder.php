<?php

namespace App\Support;

use Tests\Unit\KnowledgeGraphBuilderTest;

/**
 * Sprint 11c — the honesty rules (spec §2) as one pure function. Takes
 * already-fetched rows (plain arrays — no Eloquent, no DB access in this
 * class) and returns the graph. Every rule below is proven by
 * {@see KnowledgeGraphBuilderTest}, and the counts it produces on
 * real data are cross-checked against `hr-docs/sprints/sprint-11c/measure-graph.php`
 * run on hr-staging (plan.md §A.2, Step 3).
 *
 * The six edge types (spec §2 — every one survives one factual sentence):
 *   document → convenio        documents.convenio_id
 *   document → topic           document_topics(document_id, topic_id)
 *   fact → convenio            reference_facts.convenio_id
 *   fact → topic               reference_facts.topic_id
 *   convenio → territory       convenios.territory_id
 *   convenio → sector          convenios.sector_id
 * No other edge kind can be emitted (self::EDGE_KINDS is exhaustive, and
 * every push in this class asserts its kind against it) — this is what makes
 * "no document↔document, no fact↔fact, no document↔fact, no inference"
 * (spec §2) a property of the code rather than a promise about it. Notably
 * `documents.predecessor_document_id`, `documents.derived_from_document_id`,
 * and `reference_facts.source_document_id` are all real columns this builder
 * deliberately never turns into an edge (plan.md §A.3) — the last one is
 * surfaced instead as a `source_document` field on the fact node (plan.md
 * §A.4-A, OQ-1: decided against drawing it for v1).
 *
 * Honesty rules enforced here, each with its own fixture test:
 *   - a hub (territory/sector/topic) draws only at degree >= 2 (spec §2);
 *     a singleton folds onto its parent as text, never a floating node
 *   - the `DEV-FIXTURE-%` convenio is excluded (deploy.md §4 — not real data)
 *   - a `rejected` fact is excluded (a discard record, not knowledge)
 *   - a document with no convenio AND no edge to a drawn topic is an
 *     unreachable orphan and is dropped — but never silently: every
 *     drop is counted in `counts.hidden` (plan.md §A.4-B)
 *   - `state` is a closed, precedence-ordered enum per node type (below),
 *     so two documents in different raw states never render ambiguously
 */
final class KnowledgeGraphBuilder
{
    /** Public and exhaustive on purpose — a test iterates real output against this list. */
    public const EDGE_KINDS = [
        'document_convenio',
        'document_topic',
        'fact_convenio',
        'fact_topic',
        'convenio_territory',
        'convenio_sector',
    ];

    /** Deterministic node ordering (plan.md §A.5): group by type, then by id. */
    private const TYPE_ORDER = ['convenio' => 0, 'document' => 1, 'fact' => 2, 'sector' => 3, 'territory' => 4, 'topic' => 5];

    private const HUB_MIN_DEGREE = 2;

    /**
     * @param  array{
     *   convenios: list<array{id:int, numero:string, name:string, territory_id:?int, sector_id:?int}>,
     *   documents: list<array{id:int, uuid:string, title:string, convenio_id:?int, retrieval_status:string, tagging_status:string}>,
     *   reference_facts: list<array{id:int, uuid:string, value:string, convenio_id:int, topic_id:?int, status:string, source:string, source_document_id:?int}>,
     *   document_topics: list<array{document_id:int, topic_id:int, source:string, verified_by:?int}>,
     *   territories: list<array{id:int, name:string}>,
     *   sectors: list<array{id:int, name:string}>,
     *   topics: list<array{id:int, name:string}>,
     * }  $input
     * @return array{nodes: list<array<string,mixed>>, edges: list<array<string,mixed>>, counts: array<string,mixed>}
     */
    public static function build(array $input): array
    {
        $convenios = $input['convenios'];
        $documents = $input['documents'];
        $facts = $input['reference_facts'];
        $documentTopics = $input['document_topics'];
        $territories = $input['territories'];
        $sectors = $input['sectors'];
        $topics = $input['topics'];

        // ---- the drawn sets (spec §2's rules, applied once) --------------

        $drawnConvenios = array_values(array_filter($convenios, fn ($c) => ! self::isDevFixture($c['numero'])));
        $drawnConvenioIds = array_column($drawnConvenios, 'id');
        $drawnConvenioIds = array_combine($drawnConvenioIds, $drawnConvenioIds);

        $drawnFacts = array_values(array_filter($facts, fn ($f) => $f['status'] !== 'rejected'));

        // Topic degree counts ALL document_topics rows (not just ones on a
        // drawn document) plus non-rejected fact rows — matches
        // measure-graph.php's `drawn_topic` CTE exactly (Step 3 depends on this).
        $topicDocDegree = self::countBy($documentTopics, fn ($dt) => $dt['topic_id']);
        $topicFactDegree = self::countBy($drawnFacts, fn ($f) => $f['topic_id']);
        $drawnTopicIds = [];
        foreach ($topics as $t) {
            $degree = ($topicDocDegree[$t['id']] ?? 0) + ($topicFactDegree[$t['id']] ?? 0);
            if ($degree >= self::HUB_MIN_DEGREE) {
                $drawnTopicIds[$t['id']] = $t['id'];
            }
        }

        $convenioTerritoryDegree = self::countBy($drawnConvenios, fn ($c) => $c['territory_id']);
        $drawnTerritoryIds = [];
        foreach ($territories as $t) {
            if (($convenioTerritoryDegree[$t['id']] ?? 0) >= self::HUB_MIN_DEGREE) {
                $drawnTerritoryIds[$t['id']] = $t['id'];
            }
        }

        $convenioSectorDegree = self::countBy($drawnConvenios, fn ($c) => $c['sector_id']);
        $drawnSectorIds = [];
        foreach ($sectors as $s) {
            if (($convenioSectorDegree[$s['id']] ?? 0) >= self::HUB_MIN_DEGREE) {
                $drawnSectorIds[$s['id']] = $s['id'];
            }
        }

        // A document draws if it has a convenio, OR it has a topic tag that
        // reaches a drawn topic hub (plan.md §A.4-B) — otherwise it is an
        // unreachable orphan under the bipartite rule and is dropped.
        $docTopicsByDoc = [];
        foreach ($documentTopics as $dt) {
            $docTopicsByDoc[$dt['document_id']][] = $dt;
        }
        $drawnDocuments = [];
        $orphanDocumentCount = 0;
        foreach ($documents as $d) {
            $reachesDrawnTopic = false;
            foreach ($docTopicsByDoc[$d['id']] ?? [] as $dt) {
                if (isset($drawnTopicIds[$dt['topic_id']])) {
                    $reachesDrawnTopic = true;
                    break;
                }
            }
            if ($d['convenio_id'] !== null || $reachesDrawnTopic) {
                $drawnDocuments[] = $d;
            } else {
                $orphanDocumentCount++;
            }
        }
        $drawnDocumentIds = array_column($drawnDocuments, 'id');
        $drawnDocumentIds = array_combine($drawnDocumentIds, $drawnDocumentIds);

        // ---- nodes ---------------------------------------------------------

        $territoryById = self::keyBy($territories, 'id');
        $sectorById = self::keyBy($sectors, 'id');
        $documentById = self::keyBy($documents, 'id'); // ALL documents, incl. undrawn — needed for a fact's source_document (§A.4-A)

        $nodes = [];

        foreach ($drawnConvenios as $c) {
            $docCount = 0;
            $factCount = 0;
            foreach ($drawnDocuments as $d) {
                if ($d['convenio_id'] === $c['id']) {
                    $docCount++;
                }
            }
            foreach ($drawnFacts as $f) {
                if ($f['convenio_id'] === $c['id']) {
                    $factCount++;
                }
            }
            $nodes[] = [
                'id' => self::convenioNodeId($c['id']),
                'type' => 'convenio',
                'label' => $c['name'],
                'state' => 'scope',
                'counts' => ['documents' => $docCount, 'facts' => $factCount],
                'folded' => [
                    'territory' => ($c['territory_id'] !== null && ! isset($drawnTerritoryIds[$c['territory_id']]))
                        ? ($territoryById[$c['territory_id']]['name'] ?? null) : null,
                    'sector' => ($c['sector_id'] !== null && ! isset($drawnSectorIds[$c['sector_id']]))
                        ? ($sectorById[$c['sector_id']]['name'] ?? null) : null,
                ],
                'link' => AdminLinks::coverage($c['id']),
            ];
        }

        foreach ($drawnDocuments as $d) {
            $ownTopicRows = $docTopicsByDoc[$d['id']] ?? [];
            $hasUnverifiedAiFacet = (bool) array_filter(
                $ownTopicRows,
                fn ($dt) => $dt['source'] === 'ai_agent' && $dt['verified_by'] === null,
            );
            $state = self::documentState($d['retrieval_status'], $d['tagging_status'], $hasUnverifiedAiFacet);

            $nodes[] = [
                'id' => self::documentNodeId($d['uuid']),
                'type' => 'document',
                'label' => $d['title'],
                'state' => $state,
                'counts' => [],
                'link' => "#doc={$d['uuid']}",
            ];
        }

        foreach ($drawnFacts as $f) {
            $state = self::factState($f['status'], $f['source']);
            $sourceDoc = $f['source_document_id'] !== null ? ($documentById[$f['source_document_id']] ?? null) : null;

            $nodes[] = [
                'id' => self::factNodeId($f['uuid']),
                'type' => 'fact',
                'label' => $f['value'],
                'state' => $state,
                'counts' => [],
                'source_document' => $sourceDoc !== null ? ['id' => $sourceDoc['id'], 'title' => $sourceDoc['title']] : null,
                'link' => AdminLinks::fact($f['uuid']),
            ];
        }

        foreach ($drawnTerritoryIds as $id) {
            $nodes[] = [
                'id' => self::territoryNodeId($id),
                'type' => 'territory',
                'label' => $territoryById[$id]['name'],
                'state' => 'scope',
                'counts' => ['convenios' => $convenioTerritoryDegree[$id] ?? 0],
                'link' => null,
            ];
        }

        foreach ($drawnSectorIds as $id) {
            $nodes[] = [
                'id' => self::sectorNodeId($id),
                'type' => 'sector',
                'label' => $sectorById[$id]['name'],
                'state' => 'scope',
                'counts' => ['convenios' => $convenioSectorDegree[$id] ?? 0],
                'link' => null,
            ];
        }

        $topicById = self::keyBy($topics, 'id');
        foreach ($drawnTopicIds as $id) {
            $nodes[] = [
                'id' => self::topicNodeId($id),
                'type' => 'topic',
                'label' => $topicById[$id]['name'],
                'state' => 'scope',
                'counts' => [
                    'documents' => $topicDocDegree[$id] ?? 0,
                    'facts' => $topicFactDegree[$id] ?? 0,
                ],
                'link' => null,
            ];
        }

        // ---- edges (exactly the six kinds — spec §2) -----------------------

        $edges = [];

        foreach ($drawnDocuments as $d) {
            if ($d['convenio_id'] !== null && isset($drawnConvenioIds[$d['convenio_id']])) {
                $edges[] = self::edge('document_convenio', self::documentNodeId($d['uuid']), self::convenioNodeId($d['convenio_id']), 'system');
            }
        }

        foreach ($documentTopics as $dt) {
            if (isset($drawnDocumentIds[$dt['document_id']]) && isset($drawnTopicIds[$dt['topic_id']])) {
                $provenance = ($dt['source'] === 'ai_agent' && $dt['verified_by'] === null) ? 'unverified_ai' : 'system';
                $edges[] = self::edge(
                    'document_topic',
                    self::documentNodeId($documentById[$dt['document_id']]['uuid']),
                    self::topicNodeId($dt['topic_id']),
                    $provenance,
                );
            }
        }

        foreach ($drawnFacts as $f) {
            if (isset($drawnConvenioIds[$f['convenio_id']])) {
                $edges[] = self::edge('fact_convenio', self::factNodeId($f['uuid']), self::convenioNodeId($f['convenio_id']), 'system');
            }
            if ($f['topic_id'] !== null && isset($drawnTopicIds[$f['topic_id']])) {
                $edges[] = self::edge('fact_topic', self::factNodeId($f['uuid']), self::topicNodeId($f['topic_id']), 'system');
            }
        }

        foreach ($drawnConvenios as $c) {
            if ($c['territory_id'] !== null && isset($drawnTerritoryIds[$c['territory_id']])) {
                $edges[] = self::edge('convenio_territory', self::convenioNodeId($c['id']), self::territoryNodeId($c['territory_id']), 'system');
            }
            if ($c['sector_id'] !== null && isset($drawnSectorIds[$c['sector_id']])) {
                $edges[] = self::edge('convenio_sector', self::convenioNodeId($c['id']), self::sectorNodeId($c['sector_id']), 'system');
            }
        }

        // ---- degree (drives node size, spec §3) ----------------------------

        $degree = [];
        foreach ($edges as $e) {
            $degree[$e['source']] = ($degree[$e['source']] ?? 0) + 1;
            $degree[$e['target']] = ($degree[$e['target']] ?? 0) + 1;
        }
        foreach ($nodes as &$n) {
            $n['degree'] = $degree[$n['id']] ?? 0;
        }
        unset($n);

        // ---- deterministic order (plan.md §A.5 / §C.1) ---------------------

        usort($nodes, fn ($a, $b) => self::compareNodeIds($a['id'], $b['id']));
        usort($edges, fn ($a, $b) => [$a['kind'], $a['source'], $a['target']] <=> [$b['kind'], $b['source'], $b['target']]);

        return [
            'nodes' => $nodes,
            'edges' => $edges,
            'counts' => [
                'nodes' => count($nodes),
                'edges' => count($edges),
                'hidden' => [
                    'convenios_excluded' => count($convenios) - count($drawnConvenios),
                    'documents_orphan' => $orphanDocumentCount,
                    'facts_rejected' => count($facts) - count($drawnFacts),
                    'territories_not_drawn' => count($territories) - count($drawnTerritoryIds),
                    'sectors_not_drawn' => count($sectors) - count($drawnSectorIds),
                    'topics_not_drawn' => count($topics) - count($drawnTopicIds),
                ],
            ],
        ];
    }

    // ---- state precedence (plan.md §D.2) -----------------------------------

    /**
     * A document's colour state, in precedence order: an unverified AI facet
     * proposal outranks everything (ADR-0020's fuchsia signal is the most
     * urgent one to see), then historical, then draft, then active. Reaching
     * this rule mattered: 3 of the 8 documents carrying an unverified AI
     * facet are themselves already `tagging_status = verified` — without the
     * `tagging_status === 'under_review'` guard they would incorrectly read
     * as unverified, which is exactly the "bleed onto confirmed content"
     * ADR-0020 forbids.
     */
    public static function documentState(string $retrievalStatus, string $taggingStatus, bool $hasUnverifiedAiFacet): string
    {
        if ($taggingStatus === 'under_review' && $hasUnverifiedAiFacet) {
            return 'unverified_ai';
        }

        return match ($retrievalStatus) {
            'historical' => 'historical',
            'draft' => 'draft',
            default => 'active',
        };
    }

    /**
     * A fact's colour state. `rejected` never reaches here (dropped upstream).
     * ADR-0020: verify reverts the live styling — a `verified` AI-authored
     * fact (131 of 154 facts on real data) is NOT unverified_ai.
     */
    public static function factState(string $status, string $source): string
    {
        if ($status === 'needs_review' && $source === 'ai_agent') {
            return 'unverified_ai';
        }

        return 'verified';
    }

    // ---- small helpers ------------------------------------------------------

    private static function isDevFixture(string $numero): bool
    {
        return str_starts_with($numero, 'DEV-FIXTURE-');
    }

    private static function convenioNodeId(int $id): string
    {
        return "c:{$id}";
    }

    private static function documentNodeId(string $uuid): string
    {
        return "doc:{$uuid}";
    }

    private static function factNodeId(string $uuid): string
    {
        return "fact:{$uuid}";
    }

    private static function territoryNodeId(int $id): string
    {
        return "t:{$id}";
    }

    private static function sectorNodeId(int $id): string
    {
        return "s:{$id}";
    }

    private static function topicNodeId(int $id): string
    {
        return "tp:{$id}";
    }

    /** @return array<string,mixed> */
    private static function edge(string $kind, string $source, string $target, string $provenance): array
    {
        if (! in_array($kind, self::EDGE_KINDS, true)) {
            // Unreachable via the public API — this is the "no other edge kind
            // can be emitted" invariant made structurally impossible, not just
            // documented (spec §5.1). See test_no_edge_kind_outside_the_allowlist.
            throw new \LogicException("Refusing to emit forbidden edge kind '{$kind}'.");
        }

        return ['source' => $source, 'target' => $target, 'kind' => $kind, 'provenance' => $provenance];
    }

    /** @param list<array<string,mixed>> $rows */
    private static function countBy(array $rows, \Closure $key): array
    {
        $counts = [];
        foreach ($rows as $row) {
            $k = $key($row);
            if ($k === null) {
                continue;
            }
            $counts[$k] = ($counts[$k] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @return array<int|string,array<string,mixed>>
     */
    private static function keyBy(array $rows, string $key): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[$row[$key]] = $row;
        }

        return $out;
    }

    private static function compareNodeIds(string $a, string $b): int
    {
        [$aType, $aRest] = explode(':', $a, 2);
        [$bType, $bRest] = explode(':', $b, 2);
        $aTypeName = self::typeNameFromPrefix($aType);
        $bTypeName = self::typeNameFromPrefix($bType);
        if ($aTypeName !== $bTypeName) {
            return (self::TYPE_ORDER[$aTypeName] ?? 99) <=> (self::TYPE_ORDER[$bTypeName] ?? 99);
        }
        if (is_numeric($aRest) && is_numeric($bRest)) {
            return (int) $aRest <=> (int) $bRest;
        }

        return strcmp($aRest, $bRest);
    }

    private static function typeNameFromPrefix(string $prefix): string
    {
        return match ($prefix) {
            'c' => 'convenio',
            'doc' => 'document',
            'fact' => 'fact',
            't' => 'territory',
            's' => 'sector',
            'tp' => 'topic',
            default => $prefix,
        };
    }
}
