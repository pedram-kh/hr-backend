<?php

namespace App\Support;

/**
 * Normalization used for all controlled-vocabulary matching (territories,
 * sectors). Accent- and case-insensitive so the Basque/Spanish spelling
 * variants in filenames vs folders vs the registry sheet collapse to one key
 * (e.g. "Bizkaia", "VIZCAIA", "Vizcaya" → alias match on the same territory).
 *
 * Deliberately does NOT depend on ext-intl (not guaranteed on every host);
 * accents are stripped via an explicit transliteration map.
 */
class TextNormalizer
{
    private const TRANSLIT = [
        'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a', 'ã' => 'a',
        'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
        'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o', 'õ' => 'o',
        'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u',
        'ñ' => 'n', 'ç' => 'c',
    ];

    /**
     * Lowercase + strip accents, and nothing else — punctuation and spacing are
     * left exactly as they were.
     *
     * Extracted (Sprint 7f) so `GroupCodeNormalizer` can share this map instead of
     * carrying a second copy of it. It needs the de-accenting but NOT `key()`'s
     * punctuation handling, because a group code like `2.1` must survive with its
     * dot intact and `key()` would split it into `2 1`. Two divergent accent maps
     * in one codebase is a bug waiting to happen, so there is only this one.
     */
    public static function deaccent(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        return strtr(mb_strtolower(trim($value), 'UTF-8'), self::TRANSLIT);
    }

    /**
     * Lowercase + strip accents, uppercase, collapse any run of
     * non-alphanumerics to a single space, and trim. Returns a comparison key,
     * NOT a display string.
     */
    public static function key(?string $value): string
    {
        $value = self::deaccent($value);
        if ($value === '') {
            return '';
        }

        $value = mb_strtoupper($value, 'UTF-8');
        $value = preg_replace('/[^A-Z0-9]+/u', ' ', $value) ?? $value;

        return trim($value);
    }
}
