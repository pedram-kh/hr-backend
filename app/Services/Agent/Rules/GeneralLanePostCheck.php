<?php

namespace App\Services\Agent\Rules;

use App\Services\Agent\Rule;
use App\Services\Agent\ToolResult;
use App\Services\Agent\TurnState;
use App\Services\Agent\Verdict;
use App\Services\Answer\TurnOutcome;
use App\Services\ChatService;
use App\Support\TopicLexicon;

/**
 * Sprint 13, build step 9 (plan.md §B.6.3) — the `general_knowledge` lane's
 * deterministic post-check, run on the FINAL lane answer text before it is
 * ever surfaced. The prompt (`GENERAL_KNOWLEDGE_SYSTEM_PROMPT`, hr-ai) already
 * forbids figures/durations/entitlement language, but this rule NEVER trusts
 * the prompt alone — every pattern below is checked deterministically and any
 * hit DISCARDS the answer outright and force-escalates `general_lane_blocked`
 * with `{pattern_id, matched_span}` recorded on the trace.
 *
 * `scan()` is a plain, STATIC, callable method — not just a `Rule`-boundary
 * class — because {@see AskEmployeeWhitelist}'s own "Check 3" (§B.4's
 * pre-call whitelist, guarding a PROPOSED `ask_employee` question text, e.g.
 * "¿te refieres a las vacaciones de 30 días?") needs to call the exact same
 * scan directly, not through `RuleEngine::run()`'s `post_call:general_
 * knowledge` boundary (there is no `ToolResult` at that call site — the ask
 * hasn't been asked yet). This closes that class's own previously-flagged
 * forward-reference gap.
 *
 * Normalization runs BEFORE every pattern: lowercase, accents stripped
 * (`TopicLexicon::stripAccents()`), NBSP/thin/zero-width spaces collapsed to
 * an ordinary space, and fullwidth Unicode digits folded to ASCII — each a
 * documented real-world evasion of a naive `\d`/word-boundary regex. Legal-
 * citation tokens ("art. 38", "Ley Orgánica 3/2007", "Real Decreto 1/1995")
 * are stripped SECOND, before the digit rule (F1) ever runs, so citing the
 * law by number is never mistaken for stating a figure to the employee.
 */
final class GeneralLanePostCheck implements Rule
{
    /**
     * Spelled-out number tokens other than the articles/pronouns un/uno/una
     * (post-normalization: lowercase, accents/ñ folded). Split out so the harness audit
     * ({@see self::audit()}) is built from the SAME vocabulary as F2 and cannot drift from it.
     */
    private const F2_NUMBER_WORDS = 'cero|dos|tres|cuatro|cinco|seis|siete|ocho|nueve|diez|once|doce|trece|catorce|quince|dieci\w+|veinte|veinti\w+|treinta|cuarenta|cincuenta|sesenta|setenta|ochenta|noventa|cien(?:to|tos)?|doscient\w+|trescient\w+|quinient\w+|mil|millon\w*';

    /** F2's number tokens: the words above plus uno/una (which only count next to a quantity noun). */
    private const F2_NUMBERS = '(?:uno|una|'.self::F2_NUMBER_WORDS.')';

    /**
     * Quantity nouns that turn a spelled number into a stated figure: the CP-2 list
     * (día, mes, semana, hora, año, vez/veces, euro, jornada) plus the nouns the other
     * patterns already treat as units (F3's quincena/semestre/trimestre/bienio/trienio/
     * quinquenio, D1's set). Post-normalization spellings.
     */
    private const F2_QUANTITY_NOUNS = '(?:dias?|mes|meses|semanas?|horas?|anos?|vez|veces|euros?|jornadas?|quincenas?|semestres?|trimestres?|bienios?|trienios?|quinquenios?)';

    /**
     * The 9 pattern ids, exact regexes, POST-normalization (plan.md §B.6.3).
     * Order matters only for `matched_span` reporting (first hit wins); every
     * pattern is independently a hard block.
     *
     * @var array<string,string>
     */
    public const PATTERNS = [
        // F1: any remaining digit (after legal-citation tokens are stripped).
        'F1' => '/\d/u',
        // F2: spelled-out numbers — CP-2 precision fix (review.md, "F2 precision fix"): a spelled
        // number blocks ONLY when a quantity noun follows within two tokens ("quince días",
        // "una vez", "dos largos años", "cien euros"). Bare "una situación" / "una persona" /
        // "uno de los casos" are articles/pronouns, not figures, and now pass; "un mes" and
        // friends are still caught by D1. See F2_NUMBERS / F2_QUANTITY_NOUNS.
        'F2' => '/\b'.self::F2_NUMBERS.'\b(?:\s+\w+)?\s+'.self::F2_QUANTITY_NOUNS.'\b/u',
        // F3: fractions / multiples.
        'F3' => '/\b(mitad|medio|media|doble|triple|tercio|cuarto\s+de|quincena|semestre|trimestre|bienio|trienio|quinquenio)\b/u',
        // D1: duration with an article.
        'D1' => '/\b(un|una|al|por|cada)\s+(dia|semana|mes|ano|hora|jornada)\b/u',
        // A1: money / percentage.
        'A1' => '/€|\beur(os?)?\b|\bpor\s*ciento\b|%|\bporcentaje\b|\bsmi\b|\biprem\b|\bbase\s+reguladora\b|\bsalario\s+minimo\b/u',
        // E1: second-person entitlement. (Generic `corresponde(n)` is E2's; `te corresponde` stays here so a
        // second-person hit is reported as E1, the more specific id.)
        'E1' => '/\b(tienes|tendras|tendria[s]?)\s+derecho\b|\bte\s+corresponde(n|ra|rian)?\b|\bpuedes\s+(exigir|reclamar|pedir|solicitar)\b|\bte\s+(deben|pagaran|abonaran|concederan|tienen\s+que)\b|\b(cobraras|percibiras|recibiras|disfrutaras)\b/u',
        // E2: generic entitlement / obligation. CP-2 (forced-lane harness): ANY indicative `corresponde(n)` /
        // `corresponderá(n)` / `correspondería(n)`, not only `le corresponde` — "los derechos que corresponden a su
        // puesto" states an entitlement without naming a person. `correspondiente(s)` ("el convenio correspondiente")
        // is a different word and still passes.
        'E2' => '/\bderecho\s+a\b|\bcorrespond(?:e|en|era|eran|eria|erian)\b|\b(la\s+empresa|el\s+empresario|el\s+empleador)\s+(debe|esta\s+obligad\w*|tiene\s+que)\b|\bes\s+obligatori\w*\b|\bgarantiza\w*\b/u',
        // E3: bounds / quantity framing.
        'E3' => '/\b(como\s+)?(minimo|maximo)\b|\bal\s+menos\b|\bno\s+(podra|puede)\s+(ser\s+)?(inferior|superior)\b|\bhasta\s+un\s+(maximo|limite)\b|\bplazo\s+de\b/u',
        // X1: English leakage.
        'X1' => '/\b(entitled|you\s+are\s+owed|days?|weeks?|months?|years?|percent)\b/u',
    ];

    /** Legal-citation tokens, stripped before F1 (and every other pattern) runs. */
    private const CITATION_PATTERNS = [
        '/\bart(\.|iculo|ículo)s?\s+\d+(\.\d+)*(\s+bis)?\b/u',
        '/\bley\s+(organica\s+)?\d+\/\d{4}\b/u',
        '/\breal\s+decreto(\s+legislativo|\s+ley)?\s+\d+\/\d{4}\b/u',
    ];

    public function id(): string
    {
        return 'general_lane_post_check';
    }

    public function evaluate(TurnState $state, ?array $call, ?ToolResult $result): Verdict
    {
        if (! $result instanceof ToolResult || ! $result->terminalOutcome instanceof TurnOutcome) {
            return Verdict::allow();
        }

        if ($result->terminalOutcome->outcome !== 'answer') {
            // Already an escalation (e.g. no web source, §F.8 default) —
            // nothing for the post-check to gate.
            return Verdict::allow();
        }

        $hit = self::scan($result->terminalOutcome->answer);
        if ($hit === null) {
            return Verdict::allow();
        }

        $trace = $state->trace;
        $trace['floor_decision'] = [
            'path' => 'general_knowledge',
            'outcome' => 'escalate',
            'escalation_reason' => 'general_lane_blocked',
            'authority_used' => [],
            'note' => "general lane answer discarded — post-check hit {$hit['pattern_id']}",
        ];
        $trace['general_lane']['postcheck'] = ['passed' => false, 'hits' => [$hit]];

        $outcome = new TurnOutcome('escalate', ChatService::EMPLOYEE_ESCALATION_MESSAGE, [], $trace, 'general_lane_blocked');

        return Verdict::forceEscalate($outcome, $this->id());
    }

    /**
     * Plain, stateless scan usable OUTSIDE the rule-engine boundary (see
     * class docblock). Returns `null` on a clean pass, or
     * `{pattern_id, matched_span}` on the FIRST pattern that hits (patterns
     * are checked in `PATTERNS`' declared order).
     *
     * @return array{pattern_id:string,matched_span:string}|null
     */
    public static function scan(string $text): ?array
    {
        $normalized = self::normalize($text);

        foreach (self::CITATION_PATTERNS as $citation) {
            $normalized = preg_replace($citation, ' ', $normalized) ?? $normalized;
        }

        foreach (self::PATTERNS as $id => $pattern) {
            if (preg_match($pattern, $normalized, $m) === 1) {
                return ['pattern_id' => $id, 'matched_span' => trim($m[0])];
            }
        }

        return null;
    }

    /**
     * Sprint 13b (plan.md §3) — every pattern, every match, WITH byte offsets into the returned
     * normalized+citation-stripped text. Used by `NormalizationDiff` to decide whether a hit's
     * matched SPAN contains a token the employee did not say. Additive: `scan()` / `audit()` are
     * untouched, so the lane's own behaviour cannot change.
     *
     * @return array{normalized:string,hits:list<array{pattern_id:string,matched_span:string,start:int,end:int}>}
     */
    public static function scanAll(string $text): array
    {
        $normalized = self::normalizedForScan($text);
        $hits = [];
        foreach (self::PATTERNS as $id => $pattern) {
            if (preg_match_all($pattern, $normalized, $m, PREG_OFFSET_CAPTURE) === false) {
                continue;
            }
            foreach ($m[0] as [$span, $offset]) {
                $hits[] = ['pattern_id' => $id, 'matched_span' => trim($span), 'start' => (int) $offset, 'end' => (int) $offset + strlen($span)];
            }
        }

        return ['normalized' => $normalized, 'hits' => $hits];
    }

    /** The exact text `scan()` runs its patterns over: normalized, then legal-citation tokens blanked (same length not preserved — offsets refer to THIS string). */
    public static function normalizedForScan(string $text): string
    {
        $normalized = self::normalize($text);
        foreach (self::CITATION_PATTERNS as $citation) {
            $normalized = preg_replace($citation, ' ', $normalized) ?? $normalized;
        }

        return $normalized;
    }

    private static function normalize(string $text): string
    {
        $text = mb_strtolower($text, 'UTF-8');
        // NBSP (U+00A0), thin/hair/zero-width spaces, BOM -> ordinary space.
        $text = preg_replace('/[\x{00A0}\x{2000}-\x{200D}\x{202F}\x{FEFF}]/u', ' ', $text) ?? $text;
        $text = self::foldDigits($text);
        $text = TopicLexicon::stripAccents($text);
        // `TopicLexicon::stripAccents()` doesn't fold ñ (its callers never
        // needed to: no D1-style pattern hardcodes an "ano"/"año" pair
        // there) — D1's own regex is written against the UNACCENTED "ano",
        // so "año" must fold too, or every "al año"/"cada año" phrasing
        // would silently escape the duration check.
        $text = str_replace('ñ', 'n', $text);

        return $text;
    }

    /** Fullwidth Unicode digits (U+FF10-U+FF19), a documented digit-filter evasion. */
    private static function foldDigits(string $text): string
    {
        return preg_replace_callback('/[\x{FF10}-\x{FF19}]/u', static function (array $m): string {
            return (string) (mb_ord($m[0], 'UTF-8') - 0xFF10);
        }, $text) ?? $text;
    }

    /**
     * The INDEPENDENT leak audit used by the forced-lane harness (`eval/probes/lane-forced.php`). It is
     * deliberately BROADER than {@see self::scan()} — it never sees the citation stripping, and it looks at
     * whole words where F2 needs a quantity noun — but its spelled-number vocabulary is built from the very
     * same constants as F2, so the two cannot drift apart (CP-2, F2 precision decision):
     *
     *  - `digit`: any digit anywhere;
     *  - `spelled_number`: any spelled number other than un/uno/una, anywhere; or uno/una followed by a
     *    quantity noun within THREE tokens (F2 allows two);
     *  - `entitlement_word`: derecho / corresponde(n) / obligatori* / minimo / maximo / deberá / tienes que /
     *    puedes exigir.
     *
     * @return list<string> the audit hit ids, empty when clean
     */
    public static function audit(string $text): array
    {
        $t = self::normalize($text);
        $hits = [];
        if (preg_match('/\d/u', $t) === 1) {
            $hits[] = 'digit';
        }
        if (preg_match('/\b(?:'.self::F2_NUMBER_WORDS.')\b/u', $t) === 1
            || preg_match('/\b(?:uno|una)\b(?:\s+\w+){0,2}\s+'.self::F2_QUANTITY_NOUNS.'\b/u', $t) === 1) {
            $hits[] = 'spelled_number';
        }
        if (preg_match('/\b(derecho|corresponde|corresponden|obligatori\w+|minimo|maximo|debera|tienes que|puedes exigir)\b/u', $t) === 1) {
            $hits[] = 'entitlement_word';
        }

        return $hits;
    }

    /**
     * Sprint 13, step 9 (plan.md §B.4's Check 3) — the question pre-screen
     * (§B.6.1's condition 4), a SEPARATE regex list from `PATTERNS` above: it
     * runs on the EMPLOYEE'S OWN question (before the lane is even called),
     * not on a candidate answer. Kept on this class because it shares the
     * same normalization and the same "general lane" concern, even though it
     * guards the opposite side of the call.
     */
    public const QUESTION_PRESCREEN_PATTERNS = [
        '/\bcu[aá]nt[oa]s?\b/u',
        '/\btengo\s+derecho\b/u',
        '/\bme\s+corresponde\b/u',
        '/\bme\s+(pagan|deben|tienen\s+que)\b/u',
        '/\bpuedo\s+(exigir|pedir|reclamar)\b/u',
        '/\bcu[aá]ndo\s+(cobro|me\s+pagan)\b/u',
        '/\bdurante\s+cu[aá]nto\b/u',
        '/\bhasta\s+cu[aá]ndo\b/u',
    ];

    public static function questionPrescreenHit(string $question): bool
    {
        $normalized = self::normalize($question);
        foreach (self::QUESTION_PRESCREEN_PATTERNS as $pattern) {
            if (preg_match($pattern, $normalized) === 1) {
                return true;
            }
        }

        return false;
    }
}
