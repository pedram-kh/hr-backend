<?php

namespace App\Support;

/**
 * The result of one semantic comparison (Sprint 7d, ADR-0024) — an immutable
 * value object so a caller cannot accidentally mutate a safety decision.
 *
 * THE BAND IS DECIDED HERE, ONCE. Every caller reads `outcome`; nobody
 * re-derives it from `maxScore` and its own copy of the thresholds. There is
 * deliberately no way to construct a `clear` result from a failure: the two
 * failure constructors (`unavailable`, `nothingComparable`) both produce
 * ACKNOWLEDGE, so "the comparison did not work" can never be read as "no
 * conflict". That is the fail-toward-caution rule expressed in the type.
 */
final class SemanticComparison
{
    /** Below the review band — nothing plausibly close. */
    public const OUTCOME_CLEAR = 'clear';

    /** In the review band — show the passages, require explicit acknowledgement. */
    public const OUTCOME_ACKNOWLEDGE = 'acknowledge';

    /** At/above the conflict threshold — publish is blocked. */
    public const OUTCOME_BLOCK = 'block';

    /** @param  list<array<string,mixed>>  $matches */
    private function __construct(
        public readonly string $outcome,
        public readonly ?float $maxScore,
        public readonly array $matches,
        public readonly int $probeCount,
        public readonly int $eligibleTotal,
        public readonly float $threshold,
        public readonly float $reviewBand,
        public readonly string $reason,
        public readonly ?string $failureDetail = null,
    ) {}

    /**
     * The normal path: a real comparison ran. The band is chosen from the top
     * score of an exactly-filtered, exactly-ordered candidate set — so the
     * decision is independent of how many passages were returned for display.
     *
     * @param  list<array<string,mixed>>  $matches
     */
    public static function measured(
        ?float $maxScore,
        array $matches,
        int $probeCount,
        int $eligibleTotal,
        float $threshold,
        float $reviewBand,
    ): self {
        if ($maxScore !== null && $maxScore >= $threshold) {
            $outcome = self::OUTCOME_BLOCK;
            $reason = 'semantic_overlap';
        } elseif ($maxScore !== null && $maxScore >= $reviewBand) {
            $outcome = self::OUTCOME_ACKNOWLEDGE;
            $reason = 'semantic_near_overlap';
        } else {
            $outcome = self::OUTCOME_CLEAR;
            $reason = 'semantic_clear';
        }

        return new self($outcome, $maxScore, $matches, $probeCount, $eligibleTotal, $threshold, $reviewBand, $reason);
    }

    /**
     * hr-ai was unreachable / errored / timed out. NEVER a clear result: the
     * human is asked to acknowledge, with the cause named explicitly so the
     * prompt is distinguishable from a real near-passage (an acknowledgement the
     * human learns to click through is a fence that has quietly opened).
     */
    public static function unavailable(float $threshold, float $reviewBand, string $detail): self
    {
        return new self(
            self::OUTCOME_ACKNOWLEDGE, null, [], 0, 0, $threshold, $reviewBand,
            'semantic_compare_unavailable', $detail,
        );
    }

    /**
     * The scope HAS an active official convenio, but it contributed ZERO
     * comparable chunks — in practice a scanned convenio with no text layer
     * (the Sprint-7e OCR gap; `deploy.md` §4a names live instances). "I could not
     * read the governing text" is not evidence that the ruling doesn't conflict
     * with it, so this also falls to ACKNOWLEDGE rather than clear.
     */
    public static function nothingComparable(float $threshold, float $reviewBand, int $documentsInScope): self
    {
        return new self(
            self::OUTCOME_ACKNOWLEDGE, null, [], 0, 0, $threshold, $reviewBand,
            'semantic_no_text_to_compare',
            "{$documentsInScope} active official convenio document(s) in scope produced 0 embedded chunks "
            .'(no text layer — an unOCRd scan). The comparison could not read the governing text.',
        );
    }

    /**
     * No active official convenio in the scope at all — there is genuinely
     * nothing the ruling could be overriding. (The Sprint-4 fence would already
     * have blocked otherwise, so this is the honest empty case.)
     */
    public static function nothingInScope(float $threshold, float $reviewBand): self
    {
        return new self(
            self::OUTCOME_CLEAR, null, [], 0, 0, $threshold, $reviewBand,
            'semantic_no_official_convenio_in_scope',
        );
    }

    public function blocks(): bool
    {
        return $this->outcome === self::OUTCOME_BLOCK;
    }

    public function requiresAcknowledgement(): bool
    {
        return $this->outcome === self::OUTCOME_ACKNOWLEDGE;
    }

    /**
     * The audit payload written to `escalation_events.detail` — the matched chunk
     * ids + scores + the thresholds that were in force, so "why did this block?"
     * stays answerable after the thresholds are later re-calibrated.
     *
     * @return array<string,mixed>
     */
    public function toAudit(): array
    {
        return [
            'outcome' => $this->outcome,
            'reason' => $this->reason,
            'max_score' => $this->maxScore,
            'threshold' => $this->threshold,
            'review_band' => $this->reviewBand,
            'probe_count' => $this->probeCount,
            'eligible_total' => $this->eligibleTotal,
            'failure_detail' => $this->failureDetail,
            'matches' => $this->matches,
        ];
    }

    /**
     * The passages shown to the human in the 409 body (trimmed for transport —
     * the full text stays in `detail`).
     *
     * @return list<array<string,mixed>>
     */
    public function passages(): array
    {
        return array_map(fn (array $m) => [
            'document_uuid' => $m['document_uuid'] ?? null,
            'document_title' => $m['document_title'] ?? null,
            'page_from' => $m['page_from'] ?? null,
            'score' => $m['score'] ?? null,
            'excerpt' => $m['excerpt'] ?? null,
        ], $this->matches);
    }
}
