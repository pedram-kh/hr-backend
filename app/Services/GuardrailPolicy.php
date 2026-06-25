<?php

namespace App\Services;

use App\Models\GuardrailBlockedTopic;
use App\Models\GuardrailConfig;
use Illuminate\Support\Facades\Cache;

/**
 * The Sprint-6 guardrail READ-MODEL (ADR-0019). The single place the engine asks
 * "what is the effective guardrail value?" — and it ALWAYS returns the
 * stricter_of(baseline, admin), never a raw admin value.
 *
 * THE INVARIANT LIVES HERE, BY CONSTRUCTION:
 *  - thresholds  → max(config('hr.<floor>'), admin) — the hardcoded floor is the
 *    floor of every max, so a stored admin value can never weaken the baseline.
 *  - blocked / off-domain → a UNION on top of the GuardrailService baseline (the
 *    baseline patterns are code, not data, so they can never be removed here).
 *  - convert-by-reason → INTERSECTION(baseline allow-set, admin) — admins can
 *    only REMOVE reasons; sensitive_topic is never in the baseline set, so it is
 *    never convertible.
 *
 * Callers (ChatService / RouterService / EscalationService) receive the
 * already-combined value and never do the math themselves — so the stricter-of
 * is applied on every turn, not by a caller remembering to.
 *
 * The hot answer loop reads this CHEAPLY: a single cached array snapshot (busted
 * on every write by GuardrailConfigService), never a per-turn DB query.
 */
class GuardrailPolicy
{
    /** Cache key for the snapshot; busted on every audited write. */
    public const CACHE_KEY = 'guardrail_policy_snapshot';

    /** Snapshot TTL (seconds) — a safety net; writes bust explicitly. */
    private const CACHE_TTL = 60;

    /**
     * The HARDCODED convert-by-reason allow-set (the floor). `sensitive_topic` is
     * deliberately ABSENT — a sensitive resolution can never be converted into an
     * answerable ruling, and the admin layer can only REMOVE from this set. Adding
     * sensitive_topic via the admin layer is a no-op (intersection).
     *
     * @var list<string>
     */
    public const CONVERTIBLE_REASONS_BASELINE = [
        'low_confidence',
        'salary_coverage_gap',
        'off_domain',
        'explicit_request',
    ];

    /** Effective Check-A retrieval floor: max(hardcoded floor, admin override). */
    public function retrievalFloor(): float
    {
        return $this->maxFloor('hr.retrieval_score_floor', $this->snapshot()['retrieval_score_floor']);
    }

    /** Effective Check-C confidence floor (tiebreaker): max(floor, admin). */
    public function confidenceFloor(): float
    {
        return $this->maxFloor('hr.answer_confidence_floor', $this->snapshot()['answer_confidence_floor']);
    }

    /** Effective router confidence floor: max(floor, admin). */
    public function routerFloor(): float
    {
        return $this->maxFloor('hr.router_confidence_floor', $this->snapshot()['router_confidence_floor']);
    }

    /**
     * The admin ADD-ONLY guardrail check (knobs 2 + 3). Runs at the SAME
     * pre-router point as the GuardrailService baseline, so an admin-blocked
     * question also never reaches hr-ai. Returns the escalation reason + the
     * matched rule, or null. The baseline is checked FIRST by the caller; this is
     * purely additive (a union — it can only add escalations, never suppress one).
     *
     * @return array{fired:bool, reason:string, rule:string, pattern:string}|null
     */
    public function blockedTopicMatch(string $question): ?array
    {
        $hay = $this->normalize($question);

        foreach ($this->snapshot()['blocked'] as $row) {
            $needle = $this->normalize($row['pattern']);
            if ($needle === '') {
                continue;
            }
            // Escaped, accent-insensitive, word-boundary LITERAL — never raw regex
            // (§7.6: a raw-regex field is a ReDoS risk AND a weakening vector).
            if (preg_match('/\b'.preg_quote($needle, '/').'\b/u', $hay) === 1) {
                $reason = $row['kind'] === GuardrailBlockedTopic::KIND_OFF_DOMAIN ? 'off_domain' : 'sensitive_topic';

                return ['fired' => true, 'reason' => $reason, 'rule' => 'admin_'.$row['kind'], 'pattern' => $row['pattern']];
            }
        }

        return null;
    }

    /** The admin off-domain refusal copy, or null to use the default message. */
    public function offDomainMessage(): ?string
    {
        $msg = $this->snapshot()['off_domain_message'];

        return ($msg !== null && trim($msg) !== '') ? $msg : null;
    }

    /** The admin tone/style guidance (synthesis-local only), or null. */
    public function toneConstraints(): ?string
    {
        $tone = $this->snapshot()['tone_constraints'];

        return ($tone !== null && trim($tone) !== '') ? $tone : null;
    }

    /**
     * The effective convert-by-reason allow-set: INTERSECTION of the hardcoded
     * baseline and the admin set (restrict-only). With no admin override the
     * baseline applies unchanged.
     *
     * @return list<string>
     */
    public function convertAllowedReasons(): array
    {
        $admin = $this->snapshot()['convert_allowed_reasons'];
        if ($admin === null) {
            return self::CONVERTIBLE_REASONS_BASELINE;
        }

        return array_values(array_intersect(self::CONVERTIBLE_REASONS_BASELINE, $admin));
    }

    /** True when this escalation reason may be converted to a ruling (restrict-only). */
    public function canConvertReason(?string $reason): bool
    {
        return $reason !== null && in_array($reason, $this->convertAllowedReasons(), true);
    }

    /** Drop the cached snapshot so the next read reflects a write. */
    public static function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * max(hardcoded floor, admin override). The hardcoded floor is read LIVE from
     * config so it always tracks the true floor; a null admin override means
     * "use the floor". The admin value can never lower the result below the floor.
     */
    private function maxFloor(string $configKey, ?float $admin): float
    {
        $baseline = (float) config($configKey);

        return $admin === null ? $baseline : max($baseline, $admin);
    }

    /**
     * The cached snapshot of the admin layer (a plain array, store-agnostic — no
     * Eloquent models cached). Loaded once and reused for the whole turn.
     *
     * @return array{
     *   retrieval_score_floor:?float, answer_confidence_floor:?float,
     *   router_confidence_floor:?float, off_domain_message:?string,
     *   tone_constraints:?string, convert_allowed_reasons:?list<string>,
     *   blocked:list<array{pattern:string, kind:string}>
     * }
     */
    private function snapshot(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function (): array {
            $config = GuardrailConfig::current();
            $blocked = GuardrailBlockedTopic::query()
                ->where('enabled', true)
                ->get(['pattern', 'kind'])
                ->map(fn ($r) => ['pattern' => (string) $r->pattern, 'kind' => (string) $r->kind])
                ->all();

            return [
                'retrieval_score_floor' => $config->retrieval_score_floor !== null ? (float) $config->retrieval_score_floor : null,
                'answer_confidence_floor' => $config->answer_confidence_floor !== null ? (float) $config->answer_confidence_floor : null,
                'router_confidence_floor' => $config->router_confidence_floor !== null ? (float) $config->router_confidence_floor : null,
                'off_domain_message' => $config->off_domain_message,
                'tone_constraints' => $config->tone_constraints,
                'convert_allowed_reasons' => $config->convert_allowed_reasons,
                'blocked' => $blocked,
            ];
        });
    }

    /** Accent-insensitive, lowercased normalization (the ChatService posture). */
    private function normalize(string $s): string
    {
        $s = mb_strtolower(trim($s));

        return strtr($s, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
        ]);
    }
}
