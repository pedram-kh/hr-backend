<?php

namespace App\Services\Agent\Rules;

use App\Services\Answer\TurnOutcome;
use App\Services\GuardrailPolicy;

/**
 * Sprint 13 CP-1 decision (F.8 amendment, plan.md §B.6.1 condition 2) —
 * classifies WHY a `convenio_search` / `national_law` call left the turn
 * without an answer, so that {@see GeneralLaneAvailabilityRule} may open the
 * `general_knowledge` lane in exactly two situations and no other:
 *
 *  - `check_a_miss` — the original condition: retrieval never cleared the
 *    Check-A score floor (no synthesis call was spent).
 *  - `entailment_only` — the amendment: Check A PASSED, Check B (valid
 *    citations) passed, the deterministic figure-guard passed, and the ONLY
 *    thing that failed is the per-claim entailment gate (`/ground` found a
 *    substantive claim without support in the cited chunks). It must be a
 *    genuine gate verdict — a truncated or unparseable grounding call, a
 *    provider error, an unconfigured model, the aggregation guard and the
 *    `estatuto_fallback_gap` all stay terminal escalations.
 *
 * Deliberately a whitelist: anything not positively identified as one of the
 * two above returns `null`, so a figure-guard failure (Check A + B passed,
 * `figure_grounding.grounded === false`, `/ground` short-circuited) or any
 * other post-Check-A verdict can never open the lane by accident. The codebase
 * has no separate "conflict verdict" in the prose path; whatever a future one
 * stamps into `floor_decision`, it will not match this whitelist.
 */
final class CorpusMiss
{
    public const CHECK_A_MISS = 'check_a_miss';

    public const ENTAILMENT_ONLY = 'entailment_only';

    public static function classify(TurnOutcome $outcome): ?string
    {
        if ($outcome->outcome !== 'escalate') {
            return null;
        }

        $floor = $outcome->trace['floor_decision'] ?? null;
        if (! is_array($floor)) {
            return null;
        }

        if (($floor['check_a_retrieval'] ?? null) === false) {
            return self::CHECK_A_MISS;
        }

        return self::isEntailmentOnly($floor) ? self::ENTAILMENT_ONLY : null;
    }

    /**
     * Should a post-Check-A entailment failure be handed back to the planner
     * (as `NO_MATERIAL`, the stashed escalation kept for the finisher) instead
     * of being forced terminal? Only when the lane is effectively enabled AND
     * the question passes the explanatory pre-screen — with the lane off (the
     * default) or on an entitlement/quantity question the behaviour, and every
     * trace byte, is exactly what it was before this amendment.
     */
    public static function laneMayTakeOver(TurnOutcome $outcome, string $question, GuardrailPolicy $guardrails): bool
    {
        return self::classify($outcome) === self::ENTAILMENT_ONLY
            && $guardrails->generalLaneEnabled()
            && ! GeneralLanePostCheck::questionPrescreenHit($question);
    }

    /**
     * The `traceBlocks` entry recorded when a corpus entailment failure is
     * handed to the lane: what failed, so the audit trail shows why the lane
     * was allowed to open. No answer text (that is the escalation the employee
     * never sees).
     *
     * @return array<string,mixed>
     */
    public static function precondition(TurnOutcome $outcome): array
    {
        $grounding = $outcome->trace['floor_decision']['grounding'] ?? [];

        return ['general_lane_precondition' => [
            'kind' => self::ENTAILMENT_ONLY,
            'ungrounded_claims' => array_values($grounding['ungrounded'] ?? []),
            'authority_used' => $outcome->trace['floor_decision']['authority_used'] ?? [],
        ]];
    }

    /** @param  array<string,mixed>  $floor */
    private static function isEntailmentOnly(array $floor): bool
    {
        $grounding = $floor['grounding'] ?? null;

        return ($floor['check_a_retrieval'] ?? null) === true
            && ($floor['check_b_citations'] ?? null) === true
            && ($floor['figure_grounding']['grounded'] ?? null) === true
            && ($floor['outcome'] ?? null) === 'escalate'
            && ($floor['escalation_reason'] ?? null) === 'low_confidence'
            // The prose path stamps an estatuto-fallback marker only when the
            // fallback fired; a fallback-gap or a fallback-sourced answer
            // stays out (national-law-only retrieval is not an entailment
            // verdict on the corpus answer the lane would supplement).
            && ! array_key_exists('fallback', $floor)
            && is_array($grounding)
            && ($grounding['checked'] ?? null) === true
            && ($grounding['gate'] ?? null) === 'entailment'
            && ($grounding['grounded'] ?? null) === false
            && ($grounding['error'] ?? null) === null
            && ! ($grounding['trace_fragment']['grounding_truncated'] ?? false)
            && ! ($grounding['trace_fragment']['parse_error'] ?? false)
            && is_array($grounding['ungrounded'] ?? null)
            && $grounding['ungrounded'] !== []
            && ! in_array('<grounding check truncated>', $grounding['ungrounded'], true)
            && ! in_array('<grounding check unparseable>', $grounding['ungrounded'], true);
    }
}
