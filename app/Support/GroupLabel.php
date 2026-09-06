<?php

namespace App\Support;

/**
 * Group-label normalization and comparison (Sprint 7d, ADR-0024).
 *
 * WHY THIS EXISTS. 7b-2's duplicate detector keys on the EXACT normalized
 * `group_label`, and that is why it missed the documented version pair:
 *
 *   file 1 (Navarra · Acción e Intervención Social): "Grupos 1 y 2" = Seis Meses
 *   file 2 (same convenio, 2026):                    "Grupo 2"      = 4 meses
 *
 * `"grupos 1 y 2" !== "grupo 2"`, so a re-split version slipped the check
 * (`sprint-07b-2/review.md:168` — "Version trap ⚠ PARTIAL"). Extracting each
 * label's DIGIT SET turns that into `{1,2}` vs `{2}`, whose intersection is
 * non-empty: the pair is caught. Deterministic, auditable, no model, and
 * unit-testable on the real strings.
 *
 * ⚠ ONE IMPLEMENTATION, DELIBERATELY. `ReferenceFactAnswerService::factMatchesGroup`
 * does something similar at ANSWER time, and Sprint 7f replaces group matching
 * with structured group scope. Keeping the tokeniser here means 7f replaces ONE
 * implementation, not two. This helper is used only by the 7d duplicate-FLAG pass
 * — never at answer time — so it cannot make a group-scoped fact answerable and
 * cannot trip 7f's precondition.
 */
final class GroupLabel
{
    /** The relations two labels can stand in (see {@see relate()}). */
    public const EXACT = 'exact';

    public const OVERLAP = 'overlap';

    public const DISJOINT = 'disjoint';

    public const UNKNOWN = 'unknown';

    /** trim / collapse whitespace / case-fold. The same rule 7b-2 persists with. */
    public static function normalize(?string $label): string
    {
        if ($label === null) {
            return '';
        }

        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $label)));
    }

    /**
     * The digit tokens a label names: "Grupos 1 y 2" → [1, 2]; "Grupos 3,4 y 5" →
     * [3, 4, 5]; "Grupo 2 (resto áreas)" → [2]; "Obreros y subalternos" → [].
     *
     * Deliberately NOT a range expansion ("grupos 1 a 5" yields [1, 5], not
     * 1..5): a range is rare in the corpus and guessing its bounds would create
     * FALSE pairs, which is the one cost a flag-only pass should not pay.
     *
     * @return list<int>
     */
    public static function digits(?string $label): array
    {
        preg_match_all('/\d+/u', self::normalize($label), $m);

        $digits = array_values(array_unique(array_map('intval', $m[0] ?? [])));
        sort($digits);

        return $digits;
    }

    /**
     * How two group labels relate, for the duplicate-flag decision:
     *
     *  - EXACT    identical normalized labels → 7b-2's existing detector already
     *             covers this; the 7d pass leaves it alone.
     *  - OVERLAP  digit sets intersect but are not equal → THE NEW CATCH: a
     *             re-split version ("Grupos 1 y 2" vs "Grupo 2").
     *  - DISJOINT digit sets are both non-empty and share nothing → genuinely
     *             different groups; not a version. Skip.
     *  - UNKNOWN  either label has no digits → nothing to compare on. Skipped
     *             deliberately: flagging on prose-only labels ("Personal
     *             titulado" vs "Grupo 1") would need meaning, not tokens, and a
     *             guess here produces noise in a human queue. Recorded as an
     *             accepted gap in ADR-0024 rather than papered over.
     */
    public static function relate(?string $a, ?string $b): string
    {
        if (self::normalize($a) === self::normalize($b)) {
            return self::EXACT;
        }

        $da = self::digits($a);
        $db = self::digits($b);

        if ($da === [] || $db === []) {
            return self::UNKNOWN;
        }

        return array_intersect($da, $db) !== [] ? self::OVERLAP : self::DISJOINT;
    }

    /** A human-readable rendering of the overlap, for the audit note. */
    public static function describeOverlap(?string $a, ?string $b): string
    {
        $shared = array_values(array_intersect(self::digits($a), self::digits($b)));

        return sprintf(
            'grupos solapados: "%s" {%s} ∩ "%s" {%s} = {%s}',
            trim((string) $a), implode(',', self::digits($a)),
            trim((string) $b), implode(',', self::digits($b)),
            implode(',', $shared),
        );
    }
}
