<?php

namespace App\Services\Agent\Normalization;

use App\Services\Answer\TurnOutcome;

/**
 * Sprint 13b (plan.md §4.2/§6.1) — what a prose tool records under `normalization.consumers[]` after it
 * ran with a validated canonical unioned into retrieval: the literal pass's own top score, the canonical
 * pass's, the union's, and whether the canonical is the ONLY reason Check A cleared (`check_a_rescued`).
 * Pure: reads the `trace.retrieval` block `ProsePath` already writes; never changes an outcome.
 */
final class ConsumerTrace
{
    /** @return array<string,mixed> */
    public static function retrieval(string $tool, TurnOutcome $outcome, ?string $canonical, float $floor): array
    {
        $retrieval = is_array($outcome->trace['retrieval'] ?? null) ? $outcome->trace['retrieval'] : [];
        $passes = is_array($retrieval['passes'] ?? null) ? $retrieval['passes'] : [];

        $literal = isset($passes[0]['top_score']) ? (float) $passes[0]['top_score'] : null;
        $canonicalTop = null;
        foreach ($passes as $p) {
            if (($p['kind'] ?? null) === 'decomposed_query' && $canonical !== null && ($p['query'] ?? null) === $canonical) {
                $canonicalTop = (float) ($p['top_score'] ?? 0.0);
                break;
            }
        }
        $union = isset($retrieval['top_score']) ? (float) $retrieval['top_score'] : null;
        $rescued = $literal !== null && $union !== null && $literal < $floor && $union >= $floor;

        return [
            'tool' => $tool,
            'via' => 'planner_call',
            'literal_top_score' => $literal,
            'canonical_top_score' => $canonicalTop,
            'union_top_score' => $union,
            'retrieval_score_floor' => $floor,
            'check_a_rescued' => $rescued,
            'literal_hits_restored' => count($retrieval['rerank']['protect_main']['restored'] ?? []),
            'outcome' => $outcome->outcome,
            // an ANSWER that exists only because the canonical cleared Check A — counted and listed by the gate
            'rescued_answer' => $rescued && $outcome->outcome === 'answer',
        ];
    }
}
