<?php

namespace App\Services\Agent\Rules;

use App\Services\Answer\TurnOutcome;
use App\Services\GuardrailPolicy;
use App\Support\EscalationExplainer;

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
 * Slice 13c (plan.md §2.2) adds a THIRD whitelist value, `synthesis_abstention`, honoured ONLY when the model-knowledge
 * sub-flag is effectively on ({@see GuardrailPolicy::generalLaneModelKnowledgeEnabled()}): the corpus retrieved something
 * (Check A passed) but synthesis abstained — the model's structured `abstained` flag (recorded at
 * `floor_decision.synthesis_abstained`; true even when a related source is cited) OR the older contract form (no valid citation
 * (Check B false) at confidence <= 0.2) — no fallback marker, not a provider error. Sub-flag off = the classifier, the hand-over and every trace byte are exactly what they were.
 *
 * Correction-13c-01 (ADR-0038 amendment — "the lane is a property of the question, not of the account"): the hand-over
 * depends ONLY on (a) the question passing the explanatory pre-screen and (b) the corpus not having answered. No account
 * state excludes it any more — never-ingested (the `fallback` marker), expired / scope-under-review / no-text / not-yet-embedded
 * (`estatuto_fallback_gap`, a fourth whitelist value, `fallback_gap`) — and the reference-fact coverage gaps (no group, group
 * not approved, no sub-area, no convenio, no verified data) are handed over by {@see self::referenceFactGapMayHandOver()}.
 *
 * Deliberately a whitelist: anything not positively identified as one of the
 * values above returns `null`, so a figure-guard failure (Check A + B passed,
 * `figure_grounding.grounded === false`, `/ground` short-circuited) or any
 * other post-Check-A verdict can never open the lane by accident. The codebase
 * has no separate "conflict verdict" in the prose path; whatever a future one
 * stamps into `floor_decision`, it will not match this whitelist.
 */
final class CorpusMiss
{
    public const CHECK_A_MISS = 'check_a_miss';

    public const ENTAILMENT_ONLY = 'entailment_only';

    public const SYNTHESIS_ABSTENTION = 'synthesis_abstention';

    /**
     * Correction-13c-01 — `ProsePath` refused the Estatuto fallback because a convenio text exists but is not being served
     * (expired / under review / no text / not yet embedded): `escalation_reason = estatuto_fallback_gap`, no retrieval ran. The
     * corpus did not answer; the account's state is not a reason to keep an explanatory question out of the lane.
     */
    public const FALLBACK_GAP = 'fallback_gap';

    /** The reference-fact coverage-gap escalation reason and the one sub-outcome that is a corpus conflict, not an absence. */
    public const REFERENCE_FACT_GAP_REASON = 'reference_fact_coverage_gap';

    public const REFERENCE_FACT_CONFLICT_SUB = 'same_validity_conflict';

    /** Synthesis rule 6 (hr-ai `SYSTEM_PROMPT`): an abstention reports confidence <= 0.2. */
    public const ABSTENTION_MAX_CONFIDENCE = 0.2;

    /** @param  bool  $allowAbstention  true only when the model-knowledge sub-flag is on (Slice 13c) */
    public static function classify(TurnOutcome $outcome, bool $allowAbstention = false): ?string
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

        if (self::isFallbackGap($outcome, $floor)) {
            return self::FALLBACK_GAP;
        }

        if (self::isEntailmentOnly($floor)) {
            return self::ENTAILMENT_ONLY;
        }

        return $allowAbstention && self::isSynthesisAbstention($floor) ? self::SYNTHESIS_ABSTENTION : null;
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
        return self::handOverKind($outcome, $question, $guardrails) !== null;
    }

    /**
     * Slice 13c — WHICH shape is handed over (`entailment_only` | `synthesis_abstention` | `fallback_gap`) or null. The question gate is the
     * v1 pre-screen exactly as before, and the fail-closed allow-list (v2) when the sub-flag is on; the abstention shape is
     * recognised only with the sub-flag on.
     */
    public static function handOverKind(TurnOutcome $outcome, string $question, GuardrailPolicy $guardrails): ?string
    {
        if (! $guardrails->generalLaneEnabled()) {
            return null;
        }
        $modelKnowledge = $guardrails->generalLaneModelKnowledgeEnabled();
        $kind = self::classify($outcome, $modelKnowledge);
        if ($kind !== self::ENTAILMENT_ONLY && $kind !== self::SYNTHESIS_ABSTENTION && $kind !== self::FALLBACK_GAP) {
            return null;
        }

        // Correction-13c-01: the ONLY remaining condition is the question — no account state (fallback / expired /
        // under review / no chunks) is consulted here.
        return GeneralLanePostCheck::questionBlocked($question, $modelKnowledge) ? null : $kind;
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
        if (self::classify($outcome) === self::FALLBACK_GAP) {
            return ['general_lane_precondition' => [
                'kind' => self::FALLBACK_GAP,
                'prose_gap' => $outcome->trace['prose_gap'] ?? null,
            ]];
        }

        if (self::classify($outcome) === null && self::classify($outcome, true) === self::SYNTHESIS_ABSTENTION) {
            return ['general_lane_precondition' => [
                'kind' => self::SYNTHESIS_ABSTENTION,
                'confidence' => $outcome->trace['floor_decision']['check_c_confidence_tiebreaker']['confidence'] ?? null,
                'authority_used' => $outcome->trace['floor_decision']['authority_used'] ?? [],
            ]];
        }

        $grounding = $outcome->trace['floor_decision']['grounding'] ?? [];

        return ['general_lane_precondition' => [
            'kind' => self::ENTAILMENT_ONLY,
            'ungrounded_claims' => array_values($grounding['ungrounded'] ?? []),
            'authority_used' => $outcome->trace['floor_decision']['authority_used'] ?? [],
        ]];
    }

    /**
     * The status the planner reads back for a handed-over corpus miss. `check_a_failed` is the planner's own vocabulary for
     * "the corpus gave nothing" (hr-ai tool description); a fallback gap carries the gap class like a Check-A miss does.
     *
     * @return array<string,mixed>
     */
    public static function plannerSummary(string $kind, TurnOutcome $outcome): array
    {
        return match ($kind) {
            self::SYNTHESIS_ABSTENTION => ['status' => 'abstained'],
            self::FALLBACK_GAP => ['status' => 'check_a_failed', 'gap_class' => $outcome->trace['prose_gap']['classification'] ?? null],
            default => ['status' => 'entailment_failed'],
        };
    }

    /**
     * Correction-13c-01 — may a `reference_fact` coverage-gap escalation be handed back to the planner (so the turn continues
     * to `convenio_search`, and from there to the lane when the corpus still does not answer) instead of ending the turn?
     * Lane on AND the question passes the explanatory gate AND the gap is an absence — never `same_validity_conflict` (two
     * verified facts disagreeing is a conflict verdict, which never opens the lane) and never a composition conflict or
     * low-confidence (those carry another escalation reason).
     */
    public static function referenceFactGapMayHandOver(TurnOutcome $outcome, string $question, GuardrailPolicy $guardrails): bool
    {
        if (! $guardrails->generalLaneEnabled()) {
            return false;
        }
        if ($outcome->outcome !== 'escalate' || $outcome->escalationReason !== self::REFERENCE_FACT_GAP_REASON) {
            return false;
        }
        if (EscalationExplainer::subOutcomeOf(self::REFERENCE_FACT_GAP_REASON, $outcome->trace) === self::REFERENCE_FACT_CONFLICT_SUB) {
            return false;
        }

        return ! GeneralLanePostCheck::questionBlocked($question, $guardrails->generalLaneModelKnowledgeEnabled());
    }

    /**
     * Correction-13c-01 (second part) — may a `reference_fact_composition` failure be handed back to the planner? The composition
     * (verified fact + governing convenio prose, one synthesis, Check B, entailment) is the corpus's answer to the question; when it
     * does not produce one (`low_confidence`: the synthesis abstained / cited nothing valid / a claim was not entailed) the corpus
     * has not answered, exactly as `convenio_search`'s own abstention or entailment failure. A provider error is an outage, not a
     * miss, and a fact-vs-convenio `conflict` is a verdict — both stay terminal. Lane on AND explanatory question only.
     */
    public static function referenceFactCompositionMayHandOver(TurnOutcome $outcome, string $question, GuardrailPolicy $guardrails): bool
    {
        if (! $guardrails->generalLaneEnabled()) {
            return false;
        }
        if ($outcome->outcome !== 'escalate' || $outcome->escalationReason !== 'low_confidence') {
            return false;
        }
        $trace = $outcome->trace;
        if (($trace['floor_decision']['path'] ?? null) !== 'reference_fact_composition'
            || array_key_exists('synthesis_error', $trace['composition'] ?? [])) {
            return false;
        }

        return ! GeneralLanePostCheck::questionBlocked($question, $guardrails->generalLaneModelKnowledgeEnabled());
    }

    /** Either reference-fact hand-over: a coverage gap or a composition that did not answer. */
    public static function referenceFactMayHandOver(TurnOutcome $outcome, string $question, GuardrailPolicy $guardrails): bool
    {
        return self::referenceFactGapMayHandOver($outcome, $question, $guardrails)
            || self::referenceFactCompositionMayHandOver($outcome, $question, $guardrails);
    }

    /** @param  array<string,mixed>  $floor */
    private static function isFallbackGap(TurnOutcome $outcome, array $floor): bool
    {
        return $outcome->escalationReason === 'estatuto_fallback_gap'
            && ($floor['escalation_reason'] ?? null) === 'estatuto_fallback_gap'
            && ($floor['outcome'] ?? null) === 'escalate'
            && ! array_key_exists('check_a_retrieval', $floor);
    }

    /** @param  array<string,mixed>  $floor */
    private static function isSynthesisAbstention(array $floor): bool
    {
        // The structured flag (hr-ai `/synthesise` `abstained`): an abstention even when it cites a related source (S3c LP-14/LP-45).
        if (($floor['synthesis_abstained']['flag'] ?? false) === true) {
            return ($floor['check_a_retrieval'] ?? null) === true
                && ($floor['outcome'] ?? null) === 'escalate'
                && ($floor['escalation_reason'] ?? null) === 'low_confidence';
        }

        $confidence = $floor['check_c_confidence_tiebreaker']['confidence'] ?? null;

        return ($floor['check_a_retrieval'] ?? null) === true
            && ($floor['check_b_citations'] ?? null) === false
            && ($floor['outcome'] ?? null) === 'escalate'
            && ($floor['escalation_reason'] ?? null) === 'low_confidence'
            && ($floor['note'] ?? null) === 'no valid citations (Check B failed)'
            && is_numeric($confidence)
            && (float) $confidence <= self::ABSTENTION_MAX_CONFIDENCE;
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
