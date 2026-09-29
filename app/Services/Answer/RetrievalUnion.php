<?php

namespace App\Services\Answer;

use App\Models\Document;
use App\Services\ExtractionClient;
use App\Support\TopicLexicon;
use Illuminate\Support\Facades\Log;

/**
 * Sprint 13, build step 1 (plan.md §B.1) — `App\Services\Answer\RetrievalUnion`,
 * extracted VERBATIM from `ChatService` (`retrieveUnion`, `precedenceRerank`,
 * `mergeChunks`, `safeRetrieve`, `orderByAuthority`, `chunkTopics` —
 * `ChatService.php:1203-1467`, pre-refactor line numbers). Shared by
 * `ReferenceFactPath` and `ProsePath` — the only chunk-retrieval/ordering logic
 * either path needs, so both depend downward on this one class rather than on
 * each other.
 *
 * One deliberate, disclosed grouping choice beyond the plan's literal line
 * range: `resolveCitations()` (`ChatService.php:1671-1700`, described in the
 * plan as owned by `ProsePath`) is placed here instead, verbatim, because
 * `ReferenceFactPath::compositionCitations()` also calls it (Phase 2
 * composition citations) — putting it on the class both paths already depend
 * on avoids a `ReferenceFactPath` → `ProsePath` dependency that isn't in the
 * plan's own dependency sketch (§B.2). No logic in the method itself changed.
 */
class RetrievalUnion
{
    /** Max chunks handed to synthesis after the recall-hardening union (top by score). */
    private const SYNTHESIS_CHUNK_CAP = 10;

    /**
     * Extra synthesis slots per decomposed sub-query (Correction-03, Fix 1). A
     * COMPOUND question unions several passes (main + each sub-query + national
     * law); capping that union at the single-topic cap truncates one sub-topic's
     * recall below what a focused query would surface (e.g. Navarra's buried
     * "37 días laborables" grant chunk 7721 lands at ~#12 once vacaciones +
     * periodo-de-prueba chunks share the pool). The cap grows with the number of
     * sub-queries so each sub-topic keeps its recall; a single-topic question
     * (no sub-queries) is unchanged at SYNTHESIS_CHUNK_CAP.
     *
     * Sprint 10b (ADR-0033): also applied per decomposed_queries entry, for the
     * same reason — a situational rephrasing's recall must not be truncated
     * below what a canonically-phrased version of the same question would get.
     */
    private const COMPOUND_CAP_PER_SUBQUERY = 2;

    /** Tiny margin used to lift a governing convenio chunk just above the baseline it competes with. */
    private const PRECEDENCE_EPSILON = 0.0001;

    public function __construct(
        private readonly ExtractionClient $ai,
    ) {}

    /**
     * Recall hardening (§6): issue /retrieve for the question + each decomposed
     * sub-query + each decomposed_queries retrieval rephrasing (Sprint 10b,
     * ADR-0033) + a national-law-only pass, then UNION (dedupe by chunk_id
     * keeping the max score), sort by score desc, cap to SYNTHESIS_CHUNK_CAP.
     * /retrieve is unchanged (the union is hr-backend-side — resolved §9 F).
     *
     * `$decomposedQueries` is a SEPARATE array from `$subqueries` — subqueries
     * SPLIT a compound question into its constituent topics; decomposed_queries
     * REPHRASE a (possibly single-topic) question's underlying legal concept into
     * corpus vocabulary. Both join the SAME union the SAME way (plan.md §B.2):
     * each entry becomes one more scoped `/retrieve` pass, with `query` the ONLY
     * thing that varies — `convenio_id`/`include_national_law`/`retrieval_status`/
     * `as_of_date`/`k` are fixed from the enclosing turn's own resolved scope,
     * never read from the query text. This is the deterministic guard (plan.md
     * §B.3): a decomposed query can reach retrieval text and nothing else — no
     * scope resolve, no routing precedence, no authority.
     *
     * @param  list<string>  $subqueries
     * @param  list<string>  $decomposedQueries
     * @return array{chunks:list<array<string,mixed>>, eligible_total:int, passes:list<array<string,mixed>>, rerank:array<string,mixed>}
     */
    public function retrieveUnion(string $question, array $subqueries, ?int $convenioId, string $asOf, bool $fallback = false, array $decomposedQueries = []): array
    {
        $byChunkId = [];
        $passes = [];
        $maxEligible = 0;
        $poolK = (int) config('hr.retrieval_pool_k', 25);
        $nlK = (int) config('hr.retrieval_national_law_k', 8);

        // Sprint 10a: on the fallback path the convenio side is empty BY
        // MEASUREMENT — `classifyProseGap()` has already established there is not
        // one prose chunk to find. Passing `convenio_id: null` therefore removes
        // a filter that can only ever match nothing, and turns the scoped passes
        // into national-law-only passes. `/retrieve` is UNCHANGED (ADR-0007, and
        // spec R2): hr-ai has always returned national law alone for a null
        // convenio_id (hr-ai/app/chunks_db.py:100-119) — that is exactly what the
        // existing national-law pass below already relies on.
        $scopeConvenioId = $fallback ? null : $convenioId;

        // The main question + each sub-query + each decomposed_queries rephrasing:
        // scoped (convenio + national law). `query` is the ONLY thing that varies
        // per pass — see the deterministic-guard note on the method docblock.
        $subqueryCount = count($subqueries);
        $queries = array_merge([$question], $subqueries, $decomposedQueries);
        foreach ($queries as $i => $q) {
            $resp = $this->safeRetrieve([
                'query' => $q,
                'convenio_id' => $scopeConvenioId,
                'include_national_law' => true,
                'retrieval_status' => ['active'],
                'as_of_date' => $asOf,
                'k' => $poolK,
            ]);
            $this->mergeChunks($byChunkId, $resp['chunks'] ?? []);
            $eligible = (int) ($resp['eligible_total'] ?? 0);
            $maxEligible = max($maxEligible, $eligible);
            $kind = 'main';
            if ($i > 0 && $i <= $subqueryCount) {
                $kind = 'subquery';
            } elseif ($i > $subqueryCount) {
                $kind = 'decomposed_query';
            }
            $passes[] = [
                'kind' => $kind,
                'query' => $q,
                'returned' => count($resp['chunks'] ?? []),
                'eligible_total' => $eligible,
                'top_score' => empty($resp['chunks'] ?? []) ? 0.0 : round((float) collect($resp['chunks'])->max('score'), 6),
            ];
        }

        // National-law-only pass (convenio_id = null) — surfaces the on-topic
        // Estatuto article for a silent-convenio topic even when convenio chunks
        // dominate the scoped top-k (the Art. 14 ET recall gap).
        $nl = $this->safeRetrieve([
            'query' => $question,
            'convenio_id' => null,
            'include_national_law' => true,
            'retrieval_status' => ['active'],
            'as_of_date' => $asOf,
            'k' => $nlK,
        ]);
        $this->mergeChunks($byChunkId, $nl['chunks'] ?? []);
        $passes[] = [
            'kind' => 'national_law',
            'query' => $question,
            'returned' => count($nl['chunks'] ?? []),
            'eligible_total' => (int) ($nl['eligible_total'] ?? 0),
            'top_score' => empty($nl['chunks'] ?? []) ? 0.0 : round((float) collect($nl['chunks'])->max('score'), 6),
        ];

        // Widened-pool precedence re-rank (Correction-03, Fix 1): on the FULL union
        // (before truncation) promote a governing convenio chunk above the
        // national_law chunk that covers the SAME topic, so the convenio's figure
        // displaces the baseline instead of being discarded by the raw-score cut.
        //
        // Sprint 10a: skipped on the fallback path. The re-rank's entire job is to
        // resolve convenio-vs-baseline competition, and on this path there is no
        // convenio side to compete — every chunk is national_law, so the pass
        // would be a no-op that still rewrites `effective_score` and emits a
        // rerank trace implying an adjudication happened. Skipping it keeps the
        // trace honest about what the answer was built from.
        $merged = array_values($byChunkId);
        if ($fallback) {
            usort($merged, fn ($a, $b) => ($b['score'] ?? 0.0) <=> ($a['score'] ?? 0.0));
            $rerank = ['skipped' => 'estatuto_fallback — national-law-only pool, no convenio side to re-rank against'];
        } else {
            [$merged, $rerank] = $this->precedenceRerank($merged, $poolK);
        }
        // The synthesis cap grows with the number of sub-queries so a compound
        // union isn't truncated below its parts' recall (Correction-03, Fix 1).
        // Sprint 10b (ADR-0033, plan.md §B.2): decomposed_queries counts
        // SYMMETRICALLY with subqueries — a situational rephrasing earns the same
        // recall headroom a compound question already gets, so decomposition
        // never costs recall relative to today (count 0 → identical cap →
        // byte-for-byte with pre-10b behavior on every question it doesn't touch).
        $cap = self::SYNTHESIS_CHUNK_CAP + self::COMPOUND_CAP_PER_SUBQUERY * (count($subqueries) + count($decomposedQueries));
        $merged = array_slice($merged, 0, $cap);
        $rerank['synthesis_cap'] = $cap;

        return ['chunks' => $merged, 'eligible_total' => $maxEligible, 'passes' => $passes, 'rerank' => $rerank];
    }

    /**
     * Precedence re-rank over the widened candidate pool (Correction-03, Fix 1).
     *
     * For each governing (official_convenio / internal_hr_ruling) chunk, find the
     * national_law chunks that cover the SAME topic (shared topic anchor, see
     * TOPIC_ANCHORS); if any such baseline outranks it by raw score, lift its
     * EFFECTIVE score to just above the highest same-topic baseline so the
     * governing convenio chunk displaces the baseline in the truncated set. A
     * national_law chunk whose topic has NO convenio counterpart in the pool is
     * left untouched — so a genuinely silent convenio (e.g. trabajo a distancia)
     * still lets the Estatuto chunk fill the gap. Sorts by effective score desc
     * (raw score as the stable tiebreak). Returns [sortedChunks, rerankTrace].
     *
     * @param  list<array<string,mixed>>  $chunks
     * @return array{0: list<array<string,mixed>>, 1: array<string,mixed>}
     */
    public function precedenceRerank(array $chunks, int $poolK): array
    {
        // Precompute each chunk's topic set once.
        $topicsByIdx = [];
        $nlIdx = [];
        foreach ($chunks as $i => $c) {
            $topicsByIdx[$i] = $this->chunkTopics((string) ($c['content'] ?? ''));
            if (($c['authority_level'] ?? null) === 'national_law') {
                $nlIdx[] = $i;
            }
        }

        $boosted = [];
        foreach ($chunks as $i => $c) {
            $raw = (float) ($c['score'] ?? 0.0);
            $chunks[$i]['effective_score'] = $raw;

            if (($c['authority_level'] ?? null) === 'national_law' || empty($topicsByIdx[$i])) {
                continue; // baselines keep raw score; a topic-less convenio chunk isn't promoted
            }

            // Highest same-topic baseline score (and which chunk) this convenio
            // chunk competes with.
            $maxNl = null;
            $aboveNlId = null;
            foreach ($nlIdx as $j) {
                if (array_intersect_key($topicsByIdx[$i], $topicsByIdx[$j]) === []) {
                    continue; // different topic → not a precedence contest
                }
                $nlScore = (float) ($chunks[$j]['score'] ?? 0.0);
                if ($maxNl === null || $nlScore > $maxNl) {
                    $maxNl = $nlScore;
                    $aboveNlId = (int) ($chunks[$j]['id'] ?? 0);
                }
            }

            if ($maxNl !== null && $raw <= $maxNl) {
                $chunks[$i]['effective_score'] = $maxNl + self::PRECEDENCE_EPSILON;
                $boosted[] = [
                    'chunk_id' => (int) ($c['id'] ?? 0),
                    'topics' => array_keys($topicsByIdx[$i]),
                    'from_score' => round($raw, 6),
                    'to_effective' => round($maxNl + self::PRECEDENCE_EPSILON, 6),
                    'above_national_law_chunk_id' => $aboveNlId,
                ];
            }
        }

        usort($chunks, function ($a, $b) {
            $cmp = ($b['effective_score'] ?? ($b['score'] ?? 0.0)) <=> ($a['effective_score'] ?? ($a['score'] ?? 0.0));

            return $cmp !== 0 ? $cmp : (($b['score'] ?? 0.0) <=> ($a['score'] ?? 0.0));
        });

        return [$chunks, [
            'pool_k' => $poolK,
            'pool_size' => count($chunks),
            'epsilon' => self::PRECEDENCE_EPSILON,
            'boosted' => $boosted,
        ]];
    }

    /**
     * The set of HR topics a chunk covers, by anchor-term presence (Correction-03).
     * Delegates to the shared {@see TopicLexicon} (relocated in 7c — the same
     * lexicon the reference-fact pre-check reuses; behavior unchanged).
     *
     * @return array<string, true>
     */
    public function chunkTopics(string $content): array
    {
        return TopicLexicon::matchTopicKeys($content);
    }

    /**
     * Merge retrieved chunks into the accumulator keyed by chunk id, keeping the
     * MAX score when the same chunk surfaces from more than one query.
     *
     * @param  array<int,array<string,mixed>>  $byChunkId
     * @param  list<array<string,mixed>>  $chunks
     */
    private function mergeChunks(array &$byChunkId, array $chunks): void
    {
        foreach ($chunks as $c) {
            $id = (int) ($c['id'] ?? 0);
            if ($id === 0) {
                continue;
            }
            if (! isset($byChunkId[$id]) || ($c['score'] ?? 0.0) > ($byChunkId[$id]['score'] ?? 0.0)) {
                $byChunkId[$id] = $c;
            }
        }
    }

    /** /retrieve that never throws — a failure yields an empty result (escalation). */
    private function safeRetrieve(array $params): array
    {
        try {
            return $this->ai->retrieve($params);
        } catch (\Throwable $e) {
            Log::warning('chat: /retrieve failed', ['detail' => $e->getMessage()]);

            return ['chunks' => [], 'eligible_total' => 0];
        }
    }

    /**
     * Order convenio / internal-ruling chunks BEFORE national_law, preserving the
     * retrieval-score order within each group.
     *
     * @param  list<array<string,mixed>>  $chunks
     * @return list<array<string,mixed>>
     */
    public function orderByAuthority(array $chunks): array
    {
        $governing = [];
        $baseline = [];
        foreach ($chunks as $c) {
            if (($c['authority_level'] ?? null) === 'national_law') {
                $baseline[] = $c;
            } else {
                $governing[] = $c;
            }
        }

        return array_merge($governing, $baseline);
    }

    /**
     * Map validated citations (chunk_id → real document title + page) for display
     * and persistence. page_number = the chunk's page_from (canonical).
     *
     * @param  list<array<string,mixed>>  $validCitations
     * @param  list<array<string,mixed>>  $chunks
     * @return list<array<string,mixed>>
     */
    public function resolveCitations(array $validCitations, array $chunks): array
    {
        $byChunkId = collect($chunks)->keyBy(fn ($c) => (int) $c['id']);
        $docIds = collect($validCitations)->pluck('document_id')->unique()->all();
        $docs = Document::with(['convenio:id,name'])->whereIn('id', $docIds)->get()->keyBy('id');

        $out = [];
        foreach ($validCitations as $cit) {
            $chunkId = (int) $cit['chunk_id'];
            $chunk = $byChunkId->get($chunkId);
            $doc = $docs->get($cit['document_id']);
            $pageFrom = $cit['page_from'] ?? ($chunk['page_from'] ?? null);
            $pageTo = $cit['page_to'] ?? ($chunk['page_to'] ?? null);
            $content = (string) ($chunk['content'] ?? '');

            $out[] = [
                'chunk_id' => $chunkId,
                'document_id' => $cit['document_id'],
                'document_uuid' => $doc?->uuid,
                'document_title' => $doc?->title,
                'authority_level' => $doc?->authority_level ?? ($cit['authority_level'] ?? null),
                'page_from' => $pageFrom,
                'page_to' => $pageTo,
                'page_number' => $pageFrom,
                'snippet' => trim(mb_substr(preg_replace('/\s+/', ' ', $content) ?? '', 0, 160)),
            ];
        }

        return $out;
    }
}
