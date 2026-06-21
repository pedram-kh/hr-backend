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
     * Lowercase + strip accents, uppercase, collapse any run of
     * non-alphanumerics to a single space, and trim. Returns a comparison key,
     * NOT a display string.
     */
    public static function key(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $value = mb_strtolower($value, 'UTF-8');
        $value = strtr($value, self::TRANSLIT);
        $value = mb_strtoupper($value, 'UTF-8');
        $value = preg_replace('/[^A-Z0-9]+/u', ' ', $value) ?? $value;

        return trim($value);
    }
}
