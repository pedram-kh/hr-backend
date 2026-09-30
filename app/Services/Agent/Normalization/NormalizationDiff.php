<?php

namespace App\Services\Agent\Normalization;

use App\Services\Agent\Rules\GeneralLanePostCheck;
use App\Services\Agent\Rules\SalaryIntentPreCallRule;
use App\Services\GuardrailPolicy;
use App\Services\GuardrailService;
use App\Support\TopicLexicon;

/**
 * Sprint 13b (plan.md §3) — the pure validation logic behind `NormalizationValidationRule`.
 *
 * The planner may propose `{topic_id, canonical_query, confidence, reason}` for what the employee
 * meant. NOTHING here trusts it: the canonical is only ever used for RETRIEVAL and for picking a
 * verified fact's topic, and only if it says nothing the employee did not say. A rejection means
 * "the literal path", exactly as if the planner had never proposed anything.
 *
 * The diff is SPAN-based, not token-set based (plan.md finding 4): a token-set diff let
 * «…matrimonio de quince días» through because `días` was already in the literal and F2 needs the
 * number adjacent. Here a `GeneralLanePostCheck` pattern hit counts iff its MATCHED SPAN contains at
 * least one token that is not in the literal; plus an explicit numeric-literal check.
 *
 * All failing checks are recorded (each check can therefore have a sole-catcher test); the first is
 * the reported reason. No DB and no model call in here: everything variable is passed in.
 */
final class NormalizationDiff
{
    public const MAX_WORDS = 25;

    public const MAX_CHARS = 220;

    public const MAX_REASON = 160;

    /** Spanish provinces / autonomous communities / co-official & common territory names (normalized: lowercase, no accents, ñ→n). */
    private const TERRITORY_NAMES = [
        'alava', 'araba', 'albacete', 'alicante', 'alacant', 'almeria', 'asturias', 'avila', 'badajoz', 'baleares', 'illes balears',
        'barcelona', 'bizkaia', 'vizcaya', 'burgos', 'caceres', 'cadiz', 'cantabria', 'castellon', 'castello', 'ciudad real', 'cordoba',
        'a coruna', 'la coruna', 'coruna', 'cuenca', 'gipuzkoa', 'guipuzcoa', 'girona', 'gerona', 'granada', 'guadalajara', 'huelva',
        'huesca', 'jaen', 'leon', 'lleida', 'lerida', 'lugo', 'madrid', 'malaga', 'murcia', 'navarra', 'nafarroa', 'ourense', 'orense',
        'palencia', 'las palmas', 'pontevedra', 'la rioja', 'rioja', 'salamanca', 'santa cruz de tenerife', 'tenerife', 'segovia',
        'sevilla', 'soria', 'tarragona', 'teruel', 'toledo', 'valencia', 'valladolid', 'zamora', 'zaragoza', 'ceuta', 'melilla',
        'andalucia', 'aragon', 'canarias', 'castilla y leon', 'castilla la mancha', 'cataluna', 'catalunya', 'extremadura', 'galicia',
        'euskadi', 'pais vasco', 'comunidad valenciana', 'comunidad de madrid', 'region de murcia', 'principado de asturias',
        'mallorca', 'menorca', 'ibiza', 'espana',
    ];

    /** Group / level / category designators — never something the model may add (mirrors `AskEmployeeWhitelist::FORBIDDEN_FIELDS['professional_group']` + category). */
    private const GROUP_PATTERN = '/\b(grupos?|niveles?|nivel|sub\s*area|categori(?:a|as)|subgrupos?)\b/u';

    /** Generic territory / centre words (mirrors `AskEmployeeWhitelist::FORBIDDEN_FIELDS['territory']`). */
    private const TERRITORY_WORDS_PATTERN = '/\b(provincia|territorio|comunidad\s+autonoma|centro\s+de\s+trabajo)\b/u';

    /** Instruction-like text has no business in a canonical query. */
    private const INJECTION_PATTERN = '/\b(ignora|olvida|instrucciones|system|prompt|eres\s+un|responde\s+que|actua\s+como)\b/u';

    public function __construct(
        private readonly GuardrailService $guardrail,
        private readonly SalaryIntentPreCallRule $salaryIntent,
        private readonly ?GuardrailPolicy $policy = null,
    ) {}

    /**
     * @param  mixed  $proposal  the planner's raw `normalize_question` input
     * @param  array{id:int,name:string,status:string}|null  $topicRow  the `topics` row for `topic_id` (null = no such row)
     * @param  array<int,true>  $offeredTopicIds  ids of the approved topics offered to the planner this turn
     * @param  list<string>  $extraNames  `territories.name` ∪ `convenios.name`
     * @return array{verdict:string,rejections:list<array{rule:string,span:?string}>,proposed:array<string,mixed>,topic_used:?int,topic_name:?string,canonical_used:?string,topic_dropped:bool}
     */
    public function check(string $literal, mixed $proposal, ?array $topicRow, array $offeredTopicIds, array $extraNames, float $minTopicConfidence): array
    {
        $rej = [];
        $add = function (string $rule, ?string $span = null) use (&$rej): void {
            $rej[] = ['rule' => $rule, 'span' => $span];
        };

        // ---- shape -----------------------------------------------------------------------------
        $expectedKeys = ['topic_id', 'canonical_query', 'confidence', 'reason'];
        if (! is_array($proposal)
            || array_diff(array_keys($proposal), $expectedKeys) !== []
            || array_diff($expectedKeys, array_keys($proposal)) !== []
            || ! (is_int($proposal['topic_id']) || $proposal['topic_id'] === null)
            || ! (is_string($proposal['canonical_query']) || $proposal['canonical_query'] === null)
            || ! ((is_int($proposal['confidence']) || is_float($proposal['confidence'])) && $proposal['confidence'] >= 0 && $proposal['confidence'] <= 1)
            || ! is_string($proposal['reason'])) {
            return $this->result('rejected', [['rule' => 'shape', 'span' => null]], $this->safeProposed($proposal), null, null, null, false);
        }

        $topicId = $proposal['topic_id'];
        $canonical = $proposal['canonical_query'];
        $confidence = (float) $proposal['confidence'];
        $proposed = ['topic_id' => $topicId, 'canonical_query' => $canonical, 'confidence' => $confidence, 'reason' => mb_substr($this->clean($proposal['reason']), 0, self::MAX_REASON)];
        // `reason` is a display/audit string. The schema's maxLength 160 is advice to the model, not a safety
        // property: an over-long one is TRUNCATED here, never a rejection (S2: a valid rewrite was lost to it).

        // ---- topic -----------------------------------------------------------------------------
        $topicName = null;
        if ($topicId !== null) {
            if ($topicRow === null) {
                $add('topic_unknown');
            } elseif (($topicRow['status'] ?? null) !== 'approved') {
                $add('topic_not_approved');
            } elseif (! isset($offeredTopicIds[$topicId])) {
                $add('topic_not_offered');
            } else {
                $topicName = (string) $topicRow['name'];
            }
        }

        // ---- canonical -------------------------------------------------------------------------
        if ($canonical !== null) {
            $this->checkCanonical($literal, $canonical, $topicId, $topicName, $extraNames, $add, $confidence >= $minTopicConfidence);
        }

        if ($rej !== []) {
            return $this->result('rejected', $rej, $proposed, null, null, null, false);
        }
        if ($topicId === null && $canonical === null) {
            return $this->result('declined', [], $proposed, null, null, null, false);
        }

        // Valid. A low-confidence TOPIC is dropped (the canonical, if any, still feeds retrieval).
        $dropped = $topicId !== null && $confidence < $minTopicConfidence;

        return $this->result('accepted', [], $proposed, $dropped ? null : $topicId, $dropped ? null : $topicName, $canonical === null ? null : trim($canonical), $dropped);
    }

    /** @param  callable(string,?string):void  $add */
    private function checkCanonical(string $literal, string $canonical, ?int $topicId, ?string $topicName, array $extraNames, callable $add, bool $topicEffective = false): void
    {
        // canonical_form ------------------------------------------------------------------------
        $trimmed = trim($canonical);
        $words = $trimmed === '' ? 0 : count(preg_split('/\s+/u', $trimmed) ?: []);
        if ($trimmed === ''
            || $words > self::MAX_WORDS
            || mb_strlen($trimmed) > self::MAX_CHARS
            || preg_match('/[\r\n\p{Cc}?¿<>{}\[\]`\\\\]|https?:|www\./u', $trimmed) === 1
            || preg_match('/^[\p{L}\p{N}\s,;:.()\-\/«»"\'’“”%€]+$/u', $trimmed) !== 1
            || preg_match(self::INJECTION_PATTERN, $this->norm($trimmed)) === 1) {
            $add('canonical_form');
        }

        $literalTokens = array_fill_keys(array_column($this->tokens($this->norm($literal)), 0), true);

        // figure_not_in_literal -----------------------------------------------------------------
        $literalNums = $this->numbers($this->norm($literal));
        foreach ($this->numbers($this->norm($trimmed)) as $n) {
            if (! in_array($n, $literalNums, true)) {
                $add('figure_not_in_literal', $n);
                break;
            }
        }

        // scan:<id> — span-based -----------------------------------------------------------------
        $scan = GeneralLanePostCheck::scanAll($trimmed);
        $normalized = $scan['normalized'];
        $tokens = $this->tokens($normalized); // [token, start, end]
        $isAdded = static fn (array $t): bool => ! isset($literalTokens[$t[0]]);
        $spanHasAdded = static function (int $start, int $end) use ($tokens, $isAdded): bool {
            foreach ($tokens as $t) {
                if ($t[1] < $end && $t[2] > $start && $isAdded($t)) {
                    return true;
                }
            }

            return false;
        };
        // Sprint 13b (S1 decision A1): when the employee's own literal already speaks of a year («año», «anual»,
        // «cada año»), «al año» / «cada año» in the canonical restates it — it is not an added figure. Only D1's
        // `al|cada + año` span is exempt; `un día`, `por semana`, … and every other pattern are unchanged.
        $literalNorm = $this->norm($literal);
        $literalSpeaksOfAYear = preg_match('/\b(ano|anos|anual\w*)\b/u', $literalNorm) === 1;
        $seen = [];
        foreach ($scan['hits'] as $hit) {
            if ($hit['pattern_id'] === 'D1' && $literalSpeaksOfAYear && preg_match('/^(al|cada)\s+ano$/u', trim((string) $hit['matched_span'])) === 1) {
                continue;
            }
            if ($spanHasAdded($hit['start'], $hit['end']) && ! isset($seen[$hit['pattern_id']])) {
                $seen[$hit['pattern_id']] = true;
                $add('scan:'.$hit['pattern_id'], $hit['matched_span']);
            }
        }

        // group_designator / territory_or_convenio_name — on ADDED tokens only -----------------------
        $addedJoined = implode(' ', array_map(static fn (array $t) => $t[0], array_filter($tokens, $isAdded)));
        if (preg_match(self::GROUP_PATTERN, $addedJoined, $m) === 1) {
            $add('group_designator', $m[0]);
        }
        $hit = null;
        if (preg_match(self::TERRITORY_WORDS_PATTERN, $addedJoined, $m) === 1) {
            $hit = $m[0];
        }
        $names = array_merge(self::TERRITORY_NAMES, array_filter(array_map(fn ($n) => $this->norm((string) $n), $extraNames), fn ($n) => mb_strlen($n) >= 4));
        foreach ($names as $name) {
            if ($hit !== null) {
                break;
            }
            $name = trim((string) preg_replace('/[^a-z0-9]+/', ' ', $name));
            if ($name === '' || preg_match('/(?<![a-z0-9])'.preg_quote($name, '/').'(?![a-z0-9])/u', $normalized, $mm, PREG_OFFSET_CAPTURE) !== 1) {
                continue;
            }
            $start = (int) $mm[0][1];
            if ($spanHasAdded($start, $start + strlen($mm[0][0]))) {
                $hit = $name;
            }
        }
        if ($hit !== null) {
            $add('territory_or_convenio_name', $hit);
        }

        // pay_intent_added ------------------------------------------------------------------------
        if ($this->salaryIntent->hasPayIntent($trimmed) && ! $this->salaryIntent->hasPayIntent($literal)) {
            // Sprint 13b (S1 decision A1): with a VALIDATED `permisos retribuidos` topic, the noun «retribución»
            // names paid leave, not pay (SalaryIntentPreCallRule's own comment). Strip only `retribuci*` and
            // re-ask: plus / complemento / salario / sueldo / nómina / trienio … still reject.
            $paidLeave = $topicEffective && $topicName !== null && TopicLexicon::keyForTopicName($topicName) === 'permisos';
            $residual = $paidLeave ? (string) preg_replace('/\bretribuci\w*/iu', ' ', $trimmed) : $trimmed;
            if ($paidLeave === false || $this->salaryIntent->hasPayIntent($residual)) {
                $add('pay_intent_added');
            }
        }

        // canonical_guardrail (baseline + admin layer) — a canonical must not trip what the literal did not
        $canonicalGuard = $this->guardrail->check($trimmed);
        $adminBlock = $this->policy?->blockedTopicMatch($trimmed);
        if (($canonicalGuard['fired'] || $adminBlock !== null)
            && ! $this->guardrail->check($literal)['fired']
            && ($this->policy?->blockedTopicMatch($literal) === null)) {
            $add('canonical_guardrail', $canonicalGuard['rule'] ?? ($adminBlock['rule'] ?? null));
        }

        // topic_canonical_mismatch -----------------------------------------------------------------
        if ($topicId !== null) {
            $canonicalKeys = array_keys(TopicLexicon::matchTopicKeys($trimmed));
            if ($canonicalKeys !== []) {
                $topicKey = $topicName !== null ? TopicLexicon::keyForTopicName($topicName) : null;
                if ($topicKey === null || ! in_array($topicKey, $canonicalKeys, true)) {
                    $add('topic_canonical_mismatch', implode(',', $canonicalKeys));
                }
            }
        }
    }

    /** @return list<array{0:string,1:int,2:int}> [token, byteStart, byteEnd] */
    private function tokens(string $normalized): array
    {
        $out = [];
        if (preg_match_all('/[a-z0-9]+/', $normalized, $m, PREG_OFFSET_CAPTURE) === false) {
            return $out;
        }
        foreach ($m[0] as [$tok, $off]) {
            $out[] = [$tok, (int) $off, (int) $off + strlen($tok)];
        }

        return $out;
    }

    /** @return list<string> numeric literals, thousands separators removed, trailing punctuation trimmed */
    private function numbers(string $normalized): array
    {
        preg_match_all('/\d[\d.,]*/', $normalized, $m);

        return array_values(array_unique(array_map(static fn (string $n) => preg_replace('/(?<=\d)[.,](?=\d{3}\b)/', '', rtrim($n, '.,')) ?? $n, $m[0])));
    }

    /** Lowercase, NBSP/zero-width→space, fullwidth digits→ASCII, accents and ñ folded — the same folding `GeneralLanePostCheck` uses. */
    private function norm(string $text): string
    {
        $t = mb_strtolower($text, 'UTF-8');
        $t = preg_replace('/[\x{00A0}\x{2000}-\x{200D}\x{202F}\x{FEFF}]/u', ' ', $t) ?? $t;
        $t = preg_replace_callback('/[\x{FF10}-\x{FF19}]/u', static fn (array $m) => (string) (mb_ord($m[0], 'UTF-8') - 0xFF10), $t) ?? $t;

        return str_replace('ñ', 'n', TopicLexicon::stripAccents($t));
    }

    private function clean(string $s): string
    {
        return trim(preg_replace('/[\p{Cc}]+/u', ' ', $s) ?? $s);
    }

    /** @return array<string,mixed> a bounded, control-char-free copy of whatever the planner sent (for the trace only) */
    private function safeProposed(mixed $proposal): array
    {
        if (! is_array($proposal)) {
            return ['raw_type' => get_debug_type($proposal)];
        }
        $out = [];
        foreach (array_slice($proposal, 0, 6, true) as $k => $v) {
            $out[(string) $k] = is_scalar($v) || $v === null ? (is_string($v) ? mb_substr($this->clean($v), 0, 240) : $v) : get_debug_type($v);
        }

        return $out;
    }

    /**
     * @param  list<array{rule:string,span:?string}>  $rejections
     * @param  array<string,mixed>  $proposed
     * @return array{verdict:string,rejections:list<array{rule:string,span:?string}>,proposed:array<string,mixed>,topic_used:?int,topic_name:?string,canonical_used:?string,topic_dropped:bool}
     */
    private function result(string $verdict, array $rejections, array $proposed, ?int $topicUsed, ?string $topicName, ?string $canonicalUsed, bool $topicDropped): array
    {
        return [
            'verdict' => $verdict,
            'rejections' => $rejections,
            'proposed' => $proposed,
            'topic_used' => $topicUsed,
            'topic_name' => $topicName,
            'canonical_used' => $canonicalUsed,
            'topic_dropped' => $topicDropped,
        ];
    }
}
