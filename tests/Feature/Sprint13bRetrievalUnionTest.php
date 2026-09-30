<?php

namespace Tests\Feature;

use App\Services\Answer\RetrievalUnion;
use App\Services\ExtractionClient;
use Tests\TestCase;

/**
 * Sprint 13b (plan.md §4.2) — the canonical retrieval pass is UNION-never-replace:
 *  - `protectMain=false` (classic, Round 0, every pre-13b caller) is byte-for-byte today's behaviour;
 *  - `protectMain=true` guarantees the literal question's own top-10 survives the synthesis cap, so an extra
 *    canonical pass can add chunks and never push a literal hit out;
 *  - Check A on the union is monotone: the union's top score is never below the literal-only top score.
 */
class Sprint13bRetrievalUnionTest extends TestCase
{
    private const LITERAL = 'me quiero ir de vacas una semana';

    private const CANONICAL = 'duración de las vacaciones anuales';

    /** @return array<string,mixed> */
    private function chunk(int $id, float $score, string $authority = 'official_convenio'): array
    {
        return ['id' => $id, 'document_id' => 1, 'page_from' => 1, 'page_to' => 1, 'content' => 'texto neutro '.$id, 'score' => $score, 'authority_level' => $authority];
    }

    /**
     * @param  array<string,list<array<string,mixed>>>  $byQuery  chunks per query text (the national-law pass reuses the literal's list only when `convenio_id` is null and `$nl` says so)
     */
    private function union(array $byQuery, array $nl = []): RetrievalUnion
    {
        $ai = new class($byQuery, $nl) extends ExtractionClient
        {
            public function __construct(private array $byQuery, private array $nl) {}

            public function retrieve(array $params): array
            {
                $chunks = $params['convenio_id'] === null ? $this->nl : ($this->byQuery[$params['query']] ?? []);

                return ['chunks' => $chunks, 'eligible_total' => count($chunks)];
            }
        };

        return new RetrievalUnion($ai);
    }

    /** @return array{0:array<string,list<array<string,mixed>>>} */
    private function bank(): array
    {
        $literal = [];
        for ($i = 1; $i <= 10; $i++) {
            $literal[] = $this->chunk($i, 0.50 - ($i - 1) * 0.01);
        }
        $canonical = [];
        for ($i = 1; $i <= 12; $i++) {
            $canonical[] = $this->chunk(100 + $i, 0.90 - ($i - 1) * 0.01);
        }

        return [self::LITERAL => $literal, self::CANONICAL => $canonical];
    }

    /** @return list<int> */
    private function ids(array $result): array
    {
        return array_map(fn ($c) => (int) $c['id'], $result['chunks']);
    }

    public function test_without_protect_main_a_strong_canonical_pass_pushes_every_literal_hit_out_the_documented_reason_for_the_guard(): void
    {
        $r = $this->union($this->bank())->retrieveUnion(self::LITERAL, [], 7, '2026-01-01', false, [self::CANONICAL]);

        $this->assertSame(12, $r['rerank']['synthesis_cap']);
        $this->assertSame([], array_intersect(range(1, 10), $this->ids($r)), 'classic-style cap: the canonical pass filled all 12 slots');
        $this->assertArrayNotHasKey('protect_main', $r['rerank'], 'the pre-13b rerank trace is unchanged');
    }

    public function test_protect_main_keeps_every_literal_top_ten_hit_and_fills_the_rest_with_the_best_canonical_hits(): void
    {
        $r = $this->union($this->bank())->retrieveUnion(self::LITERAL, [], 7, '2026-01-01', false, [self::CANONICAL], true);

        $ids = $this->ids($r);
        $this->assertCount(12, $ids, 'the cap is respected');
        $this->assertSame([], array_diff(range(1, 10), $ids), 'the literal top-10 all survive');
        $this->assertContains(101, $ids);
        $this->assertContains(102, $ids, 'the two free slots go to the best canonical hits');
        $this->assertNotContains(103, $ids);
        $pm = $r['rerank']['protect_main'];
        $this->assertSame(10, $pm['top_n']);
        $this->assertSame(range(1, 10), $pm['main_top_ids']);
        $this->assertCount(10, $pm['restored'], 'all ten had been cut and were put back');
    }

    public function test_protect_main_is_a_no_op_when_the_literal_hits_already_fit(): void
    {
        $bank = $this->bank();
        $bank[self::CANONICAL] = array_slice($bank[self::CANONICAL], 0, 2); // 10 literal + 2 canonical = 12 = cap

        $plain = $this->union($bank)->retrieveUnion(self::LITERAL, [], 7, '2026-01-01', false, [self::CANONICAL]);
        $guarded = $this->union($bank)->retrieveUnion(self::LITERAL, [], 7, '2026-01-01', false, [self::CANONICAL], true);

        $this->assertSame($this->ids($plain), $this->ids($guarded));
        $this->assertSame([], $guarded['rerank']['protect_main']['restored']);
    }

    public function test_check_a_is_monotone_the_union_top_score_never_drops_below_the_literal_only_top_score(): void
    {
        // A canonical that finds NOTHING: the union is exactly the literal pass.
        $bank = [self::LITERAL => $this->bank()[self::LITERAL], self::CANONICAL => []];
        $literalOnly = $this->union($bank)->retrieveUnion(self::LITERAL, [], 7, '2026-01-01');
        $withEmptyCanonical = $this->union($bank)->retrieveUnion(self::LITERAL, [], 7, '2026-01-01', false, [self::CANONICAL], true);
        $this->assertSame($this->ids($literalOnly), $this->ids($withEmptyCanonical), 'an empty canonical pass changes nothing');

        // A canonical that finds MORE: the top score can only rise.
        $withCanonical = $this->union($this->bank())->retrieveUnion(self::LITERAL, [], 7, '2026-01-01', false, [self::CANONICAL], true);
        $top = fn (array $r) => max(array_map(fn ($c) => $c['score'], $r['chunks']));
        $this->assertGreaterThanOrEqual($top($literalOnly), $top($withCanonical));
        $this->assertSame('main', $withCanonical['passes'][0]['kind']);
        $this->assertSame('decomposed_query', $withCanonical['passes'][1]['kind']);
        $this->assertSame(self::CANONICAL, $withCanonical['passes'][1]['query']);
    }

    public function test_a_chunk_found_by_both_passes_keeps_the_higher_score(): void
    {
        $bank = [self::LITERAL => [$this->chunk(7, 0.31)], self::CANONICAL => [$this->chunk(7, 0.62)]];

        $r = $this->union($bank)->retrieveUnion(self::LITERAL, [], 7, '2026-01-01', false, [self::CANONICAL], true);

        $this->assertSame([7], $this->ids($r));
        $this->assertEqualsWithDelta(0.62, $r['chunks'][0]['score'], 1e-9);
    }
}
