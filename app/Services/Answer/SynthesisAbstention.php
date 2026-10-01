<?php

namespace App\Services\Answer;

/**
 * Slice 13c — "did synthesis abstain?" in ONE place.
 *
 * The structured signal is hr-ai's `/synthesise` `abstained` flag (the model's own JSON field, requested only with the
 * model-knowledge sub-flag on — see {@see ProsePath}) recorded at `floor_decision.synthesis_abstained`. A prose abstention
 * that still cites a related source ("No dispongo de información suficiente… [Fuente 1]") passes Check B and is invisible to
 * the older structural test (Check B false + confidence <= 0.2), which is why the flag exists (S3c: LP-14, LP-45).
 *
 * {@see self::phrase()} is the FALLBACK only: a match on the OPENING sentence of the answer. The gate uses it where no flag
 * was ever recorded (older traces, offline re-scoring of stored drafts). It mirrors hr-ai `detect_abstention_phrase`; both
 * are checked against `tests/Fixtures/synthesis-abstention-phrases.json`.
 */
final class SynthesisAbstention
{
    private const OPENERS = '/^\s*(?:'
        .'no\s+dispongo\s+de\s+informaci[oó]n\s+suficiente'
        .'|ninguna\s+de\s+las\s+fuentes(?:\s+\w+){0,2}\s+(?:menciona|contiene|recoge|define|habla|aborda|responde)'
        .'|las\s+fuentes(?:\s+\w+){0,2}\s+no\s+(?:contienen|mencionan|recogen|definen|abordan|responden)'
        .')/iu';

    public static function phrase(string $answer): bool
    {
        return $answer !== '' && preg_match(self::OPENERS, $answer) === 1;
    }

    /**
     * The recorded flag, or null when the turn never asked for one.
     *
     * @param  array<string,mixed>  $trace
     * @return array{by:?string}|null
     */
    public static function flagged(array $trace): ?array
    {
        $rec = $trace['floor_decision']['synthesis_abstained'] ?? null;

        return is_array($rec) && ($rec['flag'] ?? false) === true ? ['by' => $rec['by'] ?? null] : null;
    }

    /**
     * Gate classification of an answered turn: `flag` (structured), `phrase` (fallback on the text, no flag recorded) or null.
     *
     * @param  array<string,mixed>  $trace
     */
    public static function classify(array $trace, string $answer): ?string
    {
        if (self::flagged($trace) !== null) {
            return 'flag';
        }
        // A turn that asked for the flag and got `abstained=false` is NOT overridden by the phrase (the disagreement is in the trace).
        if (array_key_exists('abstention', $trace['synthesis'] ?? [])) {
            return null;
        }

        return self::phrase($answer) ? 'phrase' : null;
    }
}
