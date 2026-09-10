<?php

namespace App\Support;

/**
 * EscalationExplanationGuard — Sprint 7g Item 1 (ADR-0029).
 *
 * The deterministic check on the AI-written "Resumen IA" paragraph: every
 * number that appears in the structured facts must appear in the paragraph
 * (nothing silently dropped that would misrepresent the incident to HR), and
 * — the load-bearing direction — NO number or proper-noun-shaped name may
 * appear in the paragraph that isn't already in the facts (nothing invented).
 * Conservative by construction: Spanish prose preserves digits and proper
 * nouns VERBATIM even under a faithful paraphrase, so a literal-substring
 * check is a safe (if occasionally over-strict) proxy for "restates only,
 * adds nothing". A failure here is not an error — the caller falls back to
 * `EscalationExplainer::factsToSentences()`, which is always available.
 */
final class EscalationExplanationGuard
{
    /** Common Spanish capitalized tokens this domain always uses — never "new" even when capitalized. */
    private const ALWAYS_ALLOWED = [
        'El', 'La', 'Los', 'Las', 'Un', 'Una', 'Unos', 'Unas', 'Este', 'Esta', 'Estos', 'Estas',
        'Recursos', 'Humanos', 'RR', 'HH', 'IA', 'HR', 'Resumen', 'Corregir', 'Check', 'ADR',
        'Sprint', 'Tabla', 'Groups', 'Directorio', 'Documentos', 'Ajustes', 'Guardarraíles',
    ];

    public static function passes(array $facts, string $paragraph): bool
    {
        if (trim($paragraph) === '') {
            return false;
        }

        $factText = implode(' ', [
            (string) ($facts['asked'] ?? ''),
            (string) ($facts['found'] ?? ''),
            (string) ($facts['stopped_reason'] ?? ''),
            (string) ($facts['fix_action'] ?? ''),
        ]);

        $factNumbers = self::extractNumbers($factText);
        $paraNumbers = self::extractNumbers($paragraph);

        // No NEW number: every number the paragraph states must already be
        // among the facts' numbers.
        foreach ($paraNumbers as $n) {
            if (! in_array($n, $factNumbers, true)) {
                return false;
            }
        }

        // Every fact number must be represented — the paragraph must not
        // silently drop a figure that mattered to the outcome.
        foreach ($factNumbers as $n) {
            if (! in_array($n, $paraNumbers, true)) {
                return false;
            }
        }

        // No NEW proper-noun-shaped name.
        foreach (self::extractProperNouns($paragraph) as $name) {
            if (! self::containsCaseInsensitive($factText, $name)) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> normalized (thousands-separator-stripped) digit strings */
    private static function extractNumbers(string $text): array
    {
        preg_match_all('/\d[\d.,]*\d|\d/u', $text, $m);

        return array_values(array_unique(array_map(fn ($s) => self::normalizeDigits($s), $m[0])));
    }

    private static function normalizeDigits(string $s): string
    {
        return (string) preg_replace('/(?<=\d)[.,](?=\d{3}\b)/', '', $s);
    }

    /**
     * @return list<string> capitalized word/phrase runs, minus known-always-
     *                      capitalized domain tokens and minus a SINGLE
     *                      capitalized word that only appears because Spanish
     *                      orthography mandates a capital at sentence-start
     *                      (there is no semantic "properness" signal in that
     *                      position alone — flagging it would reject almost
     *                      every faithful paraphrase, since every sentence
     *                      starts with a capital). A MULTI-word capitalized
     *                      run (e.g. a real first+last name) is still flagged
     *                      even at sentence-start — genuine names are rare
     *                      enough there that this exemption would be unsafe.
     */
    private static function extractProperNouns(string $text): array
    {
        preg_match_all(
            '/\b([A-ZÁÉÍÓÚÑ][a-záéíóúñ]+(?:\s+[A-ZÁÉÍÓÚÑ][a-záéíóúñ]+)*)\b/u',
            $text,
            $m,
            PREG_OFFSET_CAPTURE
        );

        $names = [];
        foreach ($m[1] as [$candidate, $offset]) {
            // $offset is a BYTE offset (PREG_OFFSET_CAPTURE) — substr() (not
            // mb_substr()) up to it is safe: $offset always lands exactly on
            // a UTF-8 character boundary (the start of the matched group), so
            // the byte-sliced prefix is still well-formed UTF-8.
            $isSentenceStart = $offset === 0 || (bool) preg_match('/[.!?:;]\s+$/u', substr($text, 0, $offset));
            $words = explode(' ', $candidate);

            if ($isSentenceStart && count($words) === 1) {
                continue;
            }

            $words = array_values(array_filter($words, fn ($w) => ! in_array($w, self::ALWAYS_ALLOWED, true)));
            if ($words === []) {
                continue;
            }
            $names[] = implode(' ', $words);
        }

        return array_values(array_unique(array_filter($names)));
    }

    private static function containsCaseInsensitive(string $haystack, string $needle): bool
    {
        return mb_stripos($haystack, $needle) !== false;
    }
}
