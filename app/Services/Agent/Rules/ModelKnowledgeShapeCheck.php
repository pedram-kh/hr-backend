<?php

namespace App\Services\Agent\Rules;

use App\Support\TopicLexicon;

/**
 * Slice 13c (plan.md §2.4) — the SHAPE check for general-lane drafts, deliberately separate from
 * {@see GeneralLanePostCheck} (whose vocabulary is unchanged: it decides what a draft may SAY, this decides what a draft
 * without a fetched source may LOOK LIKE).
 *
 *  - S1 unverifiable citation (model basis only): an article / law / decree / BOE / sentence / URL / "[Fuente" / "según la
 *    ley" in a draft that has no source to verify it against. `GeneralLanePostCheck::scan()` deliberately strips legal-citation
 *    tokens before F1, so a fabricated "artículo 46 del Estatuto" passes it (measured); for a web-grounded draft that is fine
 *    (the excerpt can carry it and `/ground` checks the claim), for an ungrounded one it is the R4 failure.
 *  - S2 length (both bases): more than {@see self::MAX_WORDS} words.
 *  - S3 closing pointer (model basis only): the LAST sentence must point to the convenio or RR. HH. (the web prompt is
 *    unchanged and does not ask for one; the deterministic caveat appended at persist time carries the pointer there).
 *
 * Monotone: it can only add blocks. Pure and static so the forced-lane harness and the tests call the exact function the
 * rule runs.
 */
final class ModelKnowledgeShapeCheck
{
    public const BASIS_MODEL = 'model_knowledge';

    public const BASIS_WEB = 'web';

    public const MAX_WORDS = 120;

    /** S1 patterns, run on the normalized text (lowercase, accents stripped). Checked in this order; the first hit is reported. */
    private const S1 = [
        'article' => '/\b(?:articulos?|arts?)\b\.?\s*(?:n(?:o|um(?:ero)?)?\.?\s*)?\d/u',
        'real_decreto' => '/\breal(?:es)?\s+decretos?\b/u',
        'law_ref' => '/\b(?:leyes|ley)\s+(?:organica\b|general\b|\d|de\b|del\b|sobre\b)|\b(?:rdl?|rdleg|lo|lgss|ley)\s*\d+\s*\/\s*\d+/u',
        'boe' => '/\bboe\b/u',
        'case_law' => '/\b(?:sentencias?|sts[jx]?|stc|tribunal\s+(?:supremo|constitucional|superior\s+de\s+justicia))\b/u',
        'url' => '/https?:|\bwww\.|\b[a-z0-9-]+\.(?:es|gob|gov|com|org|net)\b/u',
        'fuente_marker' => '/\[\s*fuentes?\b|\bfuentes?\s*:/u',
        'segun_la_ley' => '/\bsegun\s+(?:la\s+)?(?:ley|normativa\s+vigente)\b/u',
    ];

    /** @return list<string> the S1 pattern names, for tests and the harness histogram */
    public static function s1Names(): array
    {
        return array_keys(self::S1);
    }

    /**
     * @return array{verdict:string, rule_ids:list<string>, hits:list<array{rule_id:string,detail:string}>, word_count:int}
     */
    public static function check(string $text, string $basis): array
    {
        $hits = [];
        $words = self::wordCount($text);

        if ($basis === self::BASIS_MODEL) {
            $norm = self::normalize($text);
            foreach (self::S1 as $name => $pattern) {
                if (preg_match($pattern, $norm, $m) === 1) {
                    $hits[] = ['rule_id' => 'S1', 'detail' => $name.': '.trim($m[0])];
                    break;
                }
            }
        }

        if ($words > self::MAX_WORDS) {
            $hits[] = ['rule_id' => 'S2', 'detail' => $words.' words'];
        }

        if ($basis === self::BASIS_MODEL && ! self::closingPointer($text)) {
            $hits[] = ['rule_id' => 'S3', 'detail' => 'last sentence has no convenio / RR. HH. pointer'];
        }

        return [
            'verdict' => $hits === [] ? 'pass' : 'blocked',
            'rule_ids' => array_values(array_unique(array_column($hits, 'rule_id'))),
            'hits' => $hits,
            'word_count' => $words,
        ];
    }

    public static function wordCount(string $text): int
    {
        $t = trim($text);
        if ($t === '') {
            return 0;
        }

        return count(preg_split('/\s+/u', $t) ?: []);
    }

    /** True when the last sentence mentions the convenio or RR. HH. */
    public static function closingPointer(string $text): bool
    {
        $norm = self::normalize($text);
        // "RR. HH." would otherwise be split into sentences by its own dots.
        $norm = preg_replace('/\brr\s*\.?\s*hh\s*\.?/u', 'rrhh', $norm) ?? $norm;
        $sentences = preg_split('/(?<=[.!?])\s+|\n+/u', trim($norm), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $last = $sentences === [] ? '' : (string) end($sentences);

        return preg_match('/\b(?:convenio|recursos\s+humanos|rrhh)\b/u', $last) === 1;
    }

    private static function normalize(string $text): string
    {
        $t = mb_strtolower(TopicLexicon::stripAccents($text), 'UTF-8');

        return preg_replace('/[\x{00A0}\x{2009}\x{200B}\x{202F}]+/u', ' ', $t) ?? $t;
    }
}
