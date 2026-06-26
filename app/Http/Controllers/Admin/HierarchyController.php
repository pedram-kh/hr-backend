<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\ReferenceFact;
use App\Models\Sector;
use App\Models\Territory;
use App\Models\Topic;
use App\Support\KnowledgeMap;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The lens-hierarchy read API (ADR-0001). The hierarchy is computed FROM facets
 * at query time — never a stored tree. One endpoint returns level-1 roots; a
 * second lazily returns a node's children (group nodes) or its leaf documents.
 *
 * Lens key grammar (opaque to the client — it just echoes a node's `key` back):
 *   territory: `t:{id}` | `t:national_law` | `t:unscoped`  →  `…|s:{sectorId}`  →  leaf docs
 *   sector:    `s:{id}`                                     →  `…|t:{territoryId}` →  leaf docs
 *   validity:  `v:{active|historical|draft}`               →  leaf docs
 *   topic:     `tp:{id}`                                    →  leaf docs (empty today)
 *
 * Coverage-gap flags (spec §3) are overlaid onto nodes from {@see KnowledgeMap}.
 *
 * Sprint 7b-1 (ADR-0021): Structured Reference FACTS appear as distinct,
 * badged LEAVES (`fact:{uuid}`, `knowledge_type = reference_fact`) under their
 * convenio-derived scope (territory→sector, sector→territory, topic), beside the
 * document leaves. They are scoped via the convenio exactly like documents, so
 * they slot into the SAME lens grammar — they also count toward the branch
 * counts so a scope with only facts still draws.
 */
class HierarchyController extends Controller
{
    private const LENSES = ['territory', 'sector', 'validity', 'topic'];

    public function roots(Request $request): JsonResponse
    {
        $lens = (string) $request->query('lens', 'territory');
        if (! in_array($lens, self::LENSES, true)) {
            return response()->json(['message' => "Unknown lens '{$lens}'."], 422);
        }

        $gaps = KnowledgeMap::coverageGaps();
        $expiredConvenioIds = array_column($gaps['expired_no_successor'], 'convenio_id');

        $nodes = match ($lens) {
            'territory' => $this->territoryRoots($expiredConvenioIds),
            'sector' => $this->sectorRoots(),
            'validity' => $this->validityRoots(),
            'topic' => $this->topicRoots(),
        };

        return response()->json(['lens' => $lens, 'nodes' => $nodes]);
    }

    public function children(Request $request): JsonResponse
    {
        $lens = (string) $request->query('lens', 'territory');
        $parent = (string) $request->query('parent', '');
        if (! in_array($lens, self::LENSES, true)) {
            return response()->json(['message' => "Unknown lens '{$lens}'."], 422);
        }

        $gaps = KnowledgeMap::coverageGaps();
        $leafGapByDocId = $this->leafGapIndex($gaps);
        $expiredConvenioIds = array_column($gaps['expired_no_successor'], 'convenio_id');

        // territory: t:{id} → sector groups; t:{id}|s:{sid} → leaf docs; synthetic → leaf docs
        if ($lens === 'territory') {
            if (str_contains($parent, '|s:')) {
                [$tPart, $sPart] = explode('|s:', $parent, 2);
                $territoryId = (int) substr($tPart, 2);
                $sectorId = (int) $sPart;

                return $this->leaves(
                    Document::query()->whereHas('convenio', fn ($q) => $q->where('territory_id', $territoryId)->where('sector_id', $sectorId)),
                    $leafGapByDocId,
                    $this->factLeafNodes($territoryId, $sectorId, null),
                );
            }
            if ($parent === 't:national_law') {
                return $this->leaves(Document::query()->where('authority_level', 'national_law'), $leafGapByDocId);
            }
            if ($parent === 't:unscoped') {
                return $this->leaves(Document::query()->whereNull('convenio_id')->where('authority_level', '!=', 'national_law'), $leafGapByDocId);
            }
            $territoryId = (int) substr($parent, 2);

            return response()->json(['nodes' => $this->sectorsUnderTerritory($territoryId, $expiredConvenioIds)]);
        }

        // sector: s:{id} → territory groups; s:{id}|t:{tid} → leaf docs
        if ($lens === 'sector') {
            if (str_contains($parent, '|t:')) {
                [$sPart, $tPart] = explode('|t:', $parent, 2);
                $sectorId = (int) substr($sPart, 2);
                $territoryId = (int) $tPart;

                return $this->leaves(
                    Document::query()->whereHas('convenio', fn ($q) => $q->where('sector_id', $sectorId)->where('territory_id', $territoryId)),
                    $leafGapByDocId,
                    $this->factLeafNodes($territoryId, $sectorId, null),
                );
            }
            $sectorId = (int) substr($parent, 2);

            return response()->json(['nodes' => $this->territoriesUnderSector($sectorId, $expiredConvenioIds)]);
        }

        // validity: v:{status} → leaf docs
        if ($lens === 'validity') {
            $status = substr($parent, 2);

            return $this->leaves(Document::query()->where('retrieval_status', $status), $leafGapByDocId);
        }

        // topic: tp:{id} → leaf docs via document_topics + reference facts on the topic
        $topicId = (int) substr($parent, 3);

        return $this->leaves(
            Document::query()->whereHas('topics', fn ($q) => $q->where('topics.id', $topicId)),
            $leafGapByDocId,
            $this->factLeafNodes(null, null, $topicId),
        );
    }

    // ---- roots --------------------------------------------------------------

    /** @param list<int> $expiredConvenioIds */
    private function territoryRoots(array $expiredConvenioIds): array
    {
        $counts = $this->mergeCounts(
            DB::table('documents as d')
                ->join('convenios as c', 'c.id', '=', 'd.convenio_id')
                ->select('c.territory_id', DB::raw('count(*) as cnt'))
                ->groupBy('c.territory_id')
                ->pluck('cnt', 'territory_id'),
            DB::table('reference_facts as rf')
                ->join('convenios as c', 'c.id', '=', 'rf.convenio_id')
                ->select('c.territory_id', DB::raw('count(*) as cnt'))
                ->groupBy('c.territory_id')
                ->pluck('cnt', 'territory_id'),
        );

        $expiredByTerritory = DB::table('convenios')
            ->whereIn('id', $expiredConvenioIds ?: [0])
            ->select('territory_id', DB::raw('count(*) as cnt'))
            ->groupBy('territory_id')
            ->pluck('cnt', 'territory_id');

        $nodes = [];
        foreach (Territory::orderByRaw("array_position(array['national','regional','provincial']::text[], level)")->orderBy('name')->get() as $t) {
            $count = (int) ($counts[$t->id] ?? 0);
            if ($count === 0) {
                continue; // don't draw empty branches
            }
            $nodes[] = [
                'key' => "t:{$t->id}",
                'label' => $t->name,
                'meta' => $t->level,
                'count' => $count,
                'child_kind' => 'group',
                'gap_kind' => isset($expiredByTerritory[$t->id]) ? 'expired_no_successor' : null,
            ];
        }

        // Synthetic cross-cutting nodes (ADR-0001): national law has no territory.
        $nl = Document::where('authority_level', 'national_law')->count();
        if ($nl > 0) {
            $nodes[] = ['key' => 't:national_law', 'label' => 'Ley nacional (Estatuto)', 'meta' => 'national', 'count' => $nl, 'child_kind' => 'group', 'gap_kind' => null];
        }
        // The scope-rides-on-convenio limitation: a non-national doc with no convenio.
        $unscoped = Document::whereNull('convenio_id')->where('authority_level', '!=', 'national_law')->count();
        if ($unscoped > 0) {
            $nodes[] = ['key' => 't:unscoped', 'label' => 'Sin ámbito (sin convenio)', 'meta' => null, 'count' => $unscoped, 'child_kind' => 'group', 'gap_kind' => 'unscoped'];
        }

        return $nodes;
    }

    private function sectorRoots(): array
    {
        $counts = $this->mergeCounts(
            DB::table('documents as d')
                ->join('convenios as c', 'c.id', '=', 'd.convenio_id')
                ->select('c.sector_id', DB::raw('count(*) as cnt'))
                ->groupBy('c.sector_id')
                ->pluck('cnt', 'sector_id'),
            DB::table('reference_facts as rf')
                ->join('convenios as c', 'c.id', '=', 'rf.convenio_id')
                ->select('c.sector_id', DB::raw('count(*) as cnt'))
                ->groupBy('c.sector_id')
                ->pluck('cnt', 'sector_id'),
        );

        $nodes = [];
        foreach (Sector::orderBy('name')->get() as $s) {
            $count = (int) ($counts[$s->id] ?? 0);
            if ($count === 0) {
                continue;
            }
            $nodes[] = ['key' => "s:{$s->id}", 'label' => $s->name, 'meta' => null, 'count' => $count, 'child_kind' => 'group', 'gap_kind' => null];
        }

        return $nodes;
    }

    private function validityRoots(): array
    {
        $counts = Document::select('retrieval_status', DB::raw('count(*) as cnt'))
            ->groupBy('retrieval_status')->pluck('cnt', 'retrieval_status');

        $nodes = [];
        foreach (['active', 'historical', 'draft'] as $status) {
            $count = (int) ($counts[$status] ?? 0);
            if ($count === 0) {
                continue;
            }
            $nodes[] = ['key' => "v:{$status}", 'label' => ucfirst($status), 'meta' => null, 'count' => $count, 'child_kind' => 'leaf-parent', 'gap_kind' => null];
        }

        return $nodes;
    }

    private function topicRoots(): array
    {
        $counts = $this->mergeCounts(
            DB::table('document_topics')
                ->select('topic_id', DB::raw('count(*) as cnt'))
                ->groupBy('topic_id')->pluck('cnt', 'topic_id'),
            DB::table('reference_facts')
                ->whereNotNull('topic_id')
                ->select('topic_id', DB::raw('count(*) as cnt'))
                ->groupBy('topic_id')->pluck('cnt', 'topic_id'),
        );

        $nodes = [];
        foreach (Topic::where('status', 'approved')->orderBy('name')->get() as $tp) {
            $nodes[] = [
                'key' => "tp:{$tp->id}",
                'label' => $tp->name,
                'meta' => null,
                'count' => (int) ($counts[$tp->id] ?? 0),
                'child_kind' => 'leaf-parent',
                'gap_kind' => null,
            ];
        }

        return $nodes;
    }

    /**
     * Sum two pluck maps (keyed by the same id) into one — lets a scope that has
     * only reference facts (no documents) still draw a branch.
     *
     * @return \Illuminate\Support\Collection<int,int>
     */
    private function mergeCounts(\Illuminate\Support\Collection $a, \Illuminate\Support\Collection $b): \Illuminate\Support\Collection
    {
        $merged = $a->map(fn ($v) => (int) $v);
        foreach ($b as $key => $cnt) {
            $merged[$key] = ($merged[$key] ?? 0) + (int) $cnt;
        }

        return $merged;
    }

    // ---- level-2 groups -----------------------------------------------------

    /** @param list<int> $expiredConvenioIds */
    private function sectorsUnderTerritory(int $territoryId, array $expiredConvenioIds): array
    {
        $expiredArr = 'array['.(implode(',', array_map('intval', $expiredConvenioIds)) ?: 'null').']';

        $docRows = DB::table('documents as d')
            ->join('convenios as c', 'c.id', '=', 'd.convenio_id')
            ->where('c.territory_id', $territoryId)
            ->select('c.sector_id', DB::raw('count(*) as cnt'),
                DB::raw("bool_or(c.id = any({$expiredArr})) as has_gap"))
            ->groupBy('c.sector_id')->get()->keyBy('sector_id');

        $factRows = DB::table('reference_facts as rf')
            ->join('convenios as c', 'c.id', '=', 'rf.convenio_id')
            ->where('c.territory_id', $territoryId)
            ->select('c.sector_id', DB::raw('count(*) as cnt'))
            ->groupBy('c.sector_id')->get()->keyBy('sector_id');

        $sectorIds = $docRows->keys()->merge($factRows->keys())->unique();

        return Sector::whereIn('id', $sectorIds)->orderBy('name')->get()->map(fn ($s) => [
            'key' => "t:{$territoryId}|s:{$s->id}",
            'label' => $s->name,
            'meta' => null,
            'count' => (int) ($docRows[$s->id]->cnt ?? 0) + (int) ($factRows[$s->id]->cnt ?? 0),
            'child_kind' => 'leaf-parent',
            'gap_kind' => ($docRows[$s->id]->has_gap ?? false) ? 'expired_no_successor' : null,
        ])->values()->all();
    }

    /** @param list<int> $expiredConvenioIds */
    private function territoriesUnderSector(int $sectorId, array $expiredConvenioIds): array
    {
        $expiredArr = 'array['.(implode(',', array_map('intval', $expiredConvenioIds)) ?: 'null').']';

        $docRows = DB::table('documents as d')
            ->join('convenios as c', 'c.id', '=', 'd.convenio_id')
            ->where('c.sector_id', $sectorId)
            ->select('c.territory_id', DB::raw('count(*) as cnt'),
                DB::raw("bool_or(c.id = any({$expiredArr})) as has_gap"))
            ->groupBy('c.territory_id')->get()->keyBy('territory_id');

        $factRows = DB::table('reference_facts as rf')
            ->join('convenios as c', 'c.id', '=', 'rf.convenio_id')
            ->where('c.sector_id', $sectorId)
            ->select('c.territory_id', DB::raw('count(*) as cnt'))
            ->groupBy('c.territory_id')->get()->keyBy('territory_id');

        $territoryIds = $docRows->keys()->merge($factRows->keys())->unique();

        return Territory::whereIn('id', $territoryIds)->orderBy('name')->get()->map(fn ($t) => [
            'key' => "s:{$sectorId}|t:{$t->id}",
            'label' => $t->name,
            'meta' => null,
            'count' => (int) ($docRows[$t->id]->cnt ?? 0) + (int) ($factRows[$t->id]->cnt ?? 0),
            'child_kind' => 'leaf-parent',
            'gap_kind' => ($docRows[$t->id]->has_gap ?? false) ? 'expired_no_successor' : null,
        ])->values()->all();
    }

    // ---- leaves -------------------------------------------------------------

    /**
     * Map a document query to leaf nodes (the card-openable tier). Sprint 7b-1:
     * `$factNodes` are the distinct reference-fact leaves under the same scope —
     * appended after the documents so both knowledge types show on one branch.
     *
     * @param  array<int,string>  $leafGapByDocId
     * @param  list<array<string,mixed>>  $factNodes
     */
    private function leaves(\Illuminate\Database\Eloquent\Builder $query, array $leafGapByDocId, array $factNodes = []): JsonResponse
    {
        $docs = $query->with(['documentType:id,code,name'])
            ->orderByDesc('id')
            ->get(['id', 'uuid', 'title', 'document_type_id', 'retrieval_status', 'tagging_status', 'authority_level', 'validity_start', 'validity_end']);

        $nodes = $docs->map(fn (Document $d) => [
            'key' => "doc:{$d->uuid}",
            'label' => $d->title,
            'child_kind' => 'leaf',
            'knowledge_type' => 'document',
            'doc_uuid' => $d->uuid,
            'document_type' => $d->documentType?->code,
            'retrieval_status' => $d->retrieval_status,
            'tagging_status' => $d->tagging_status,
            'authority_level' => $d->authority_level,
            'validity_start' => $d->validity_start?->toDateString(),
            'validity_end' => $d->validity_end?->toDateString(),
            'gap_kind' => $leafGapByDocId[$d->id] ?? null,
        ])->all();

        return response()->json(['nodes' => array_merge($nodes, $factNodes)]);
    }

    /**
     * The distinct reference-fact leaves under a scope (Sprint 7b-1, ADR-0021).
     * Scoped via the convenio (territory/sector) and/or topic — exactly the
     * document grammar. `knowledge_type = reference_fact` is the badge signal;
     * `is_ai_proposed` is always false in 7b-1 (no ai_agent writer — fuchsia is
     * reserved for 7b-2).
     *
     * @return list<array<string,mixed>>
     */
    private function factLeafNodes(?int $territoryId, ?int $sectorId, ?int $topicId): array
    {
        $query = ReferenceFact::query()->with(['convenio:id,numero', 'topic:id,name'])->orderByDesc('id');

        if ($territoryId !== null || $sectorId !== null) {
            $query->whereHas('convenio', function ($c) use ($territoryId, $sectorId) {
                if ($territoryId !== null) {
                    $c->where('territory_id', $territoryId);
                }
                if ($sectorId !== null) {
                    $c->where('sector_id', $sectorId);
                }
            });
        }
        if ($topicId !== null) {
            $query->where('topic_id', $topicId);
        }

        return $query->get()->map(fn (ReferenceFact $f) => [
            'key' => "fact:{$f->uuid}",
            'label' => Str::limit($f->value, 80),
            'child_kind' => 'leaf',
            'knowledge_type' => 'reference_fact',
            'fact_uuid' => $f->uuid,
            'status' => $f->status,
            'source' => $f->source,
            'authority_level' => $f->authority_level,
            'topic' => $f->topic?->name,
            'validity_start' => $f->validity_start?->toDateString(),
            'validity_end' => $f->validity_end?->toDateString(),
            // unverified-AI fuchsia is 7b-2 only; a manual fact is never fuchsia.
            'is_ai_proposed' => $f->source === 'ai_agent' && $f->status === 'needs_review',
            'gap_kind' => null,
        ])->all();
    }

    /**
     * Build a doc-id → gap_kind index for the per-document (leaf) gap classes.
     * `unanswerable` outranks `suspected_mistag` outranks `date_expired_active`.
     *
     * @param  array<string,list<array<string,mixed>>>  $gaps
     * @return array<int,string>
     */
    private function leafGapIndex(array $gaps): array
    {
        $index = [];
        foreach ($gaps['date_expired_active'] as $r) {
            $index[(int) $r['id']] = 'date_expired_active';
        }
        foreach ($gaps['suspected_mistag'] as $r) {
            $index[(int) $r['id']] = 'suspected_mistag';
        }
        foreach ($gaps['unanswerable'] as $r) {
            $index[(int) $r['id']] = 'unanswerable';
        }

        return $index;
    }
}
