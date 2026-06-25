<?php

namespace App\Services;

use App\Models\Admin;
use App\Models\GuardrailBlockedTopic;
use App\Models\GuardrailConfig;
use App\Models\GuardrailConfigEvent;
use Illuminate\Support\Facades\DB;

/**
 * The Sprint-6 guardrail-config WRITE surface (ADR-0019). hr-backend owns all
 * writes (ADR-0007). Every accepted change is:
 *   1. VALIDATED against the hardcoded floor — a below-floor threshold is
 *      REJECTED (never clamped); a tone string with gate-bypass phrasing is
 *      rejected. (The controller surfaces these as 422 + a clear message.)
 *   2. WRITTEN in one transaction with an append-only `guardrail_config_events`
 *      row (old→new, actor) — the same posture as escalation_events / tag_events.
 *   3. CACHE-BUSTED so the next answer-loop turn reads the new value.
 *
 * This service never touches GuardrailService or config/hr.php — the hardcoded
 * baseline stays code, uneditable.
 */
class GuardrailConfigService
{
    /** The three threshold knobs and the config key that is their hardcoded floor. */
    public const THRESHOLD_FLOORS = [
        'retrieval_score_floor' => 'hr.retrieval_score_floor',
        'answer_confidence_floor' => 'hr.answer_confidence_floor',
        'router_confidence_floor' => 'hr.router_confidence_floor',
    ];

    /** Tone guidance is style-only + short; this caps abuse surface. */
    public const TONE_MAX_LEN = 280;

    /**
     * Phrases that would try to turn "style" into "bypass a gate". Defense in
     * depth — the structural guarantee is that gates are downstream of tone, but
     * we still refuse to store obvious override instructions. Accent-insensitive,
     * lowercased substring match.
     *
     * @var list<string>
     */
    private const TONE_OVERRIDE_PHRASES = [
        'ignora las fuentes',
        'ignora la fuente',
        'sin fuentes',
        'sin cita',
        'sin citar',
        'aunque no haya fuente',
        'aunque no haya cita',
        'aunque no este fundamentad',
        'no escales',
        'no derives',
        'no escalar',
        'responde siempre',
        'responde aunque',
        'inventa',
        'invéntate',
        'inventate',
        'adivina',
        'usa tu conocimiento',
        'conocimiento general',
        'salta la verificacion',
        'omite la verificacion',
        'no verifiques',
    ];

    /**
     * The first threshold in $data that is below its hardcoded floor, or null.
     * Reject (not clamp): the admin sees the boundary. Reads the floor LIVE from
     * config so it always tracks the true floor.
     *
     * @param  array<string,mixed>  $data
     * @return array{field:string, floor:float, value:float}|null
     */
    public function floorViolation(array $data): ?array
    {
        foreach (self::THRESHOLD_FLOORS as $field => $configKey) {
            if (! array_key_exists($field, $data) || $data[$field] === null) {
                continue; // null = revert to the floor; absent = unchanged
            }
            $value = (float) $data[$field];
            $floor = (float) config($configKey);
            if ($value < $floor) {
                return ['field' => $field, 'floor' => $floor, 'value' => $value];
            }
        }

        return null;
    }

    /**
     * A gate-bypass phrase found in the tone string, or null. The sanitizer that
     * refuses to let "style" become "unlock".
     */
    public function toneViolation(?string $tone): ?string
    {
        if ($tone === null || trim($tone) === '') {
            return null;
        }
        $hay = $this->normalize($tone);
        foreach (self::TONE_OVERRIDE_PHRASES as $phrase) {
            if (str_contains($hay, $this->normalize($phrase))) {
                return $phrase;
            }
        }

        return null;
    }

    /**
     * Apply the provided config fields (only those PRESENT in $data are touched),
     * auditing every actual change and busting the cache. Assumes the controller
     * already ran floorViolation()/toneViolation() and rejected on violation.
     *
     * @param  array<string,mixed>  $data
     */
    public function update(array $data, Admin $actor): GuardrailConfig
    {
        return DB::transaction(function () use ($data, $actor) {
            $config = GuardrailConfig::current();

            $fields = ['retrieval_score_floor', 'answer_confidence_floor', 'router_confidence_floor', 'off_domain_message', 'tone_constraints', 'convert_allowed_reasons'];
            foreach ($fields as $field) {
                if (! array_key_exists($field, $data)) {
                    continue;
                }
                $new = $data[$field];
                $old = $config->{$field};
                if ($this->normalizeForCompare($old) === $this->normalizeForCompare($new)) {
                    continue; // no real change → no write, no audit row
                }
                $config->{$field} = $new;
                $this->audit($field, $old, $new, $actor, null);
            }

            $config->updated_by = $actor->id;
            $config->save();

            GuardrailPolicy::flush();

            return $config->fresh();
        });
    }

    /** Add an admin blocked-topic / off-domain trigger (add-only) + audit + flush. */
    public function addBlockedTopic(string $pattern, string $kind, Admin $actor): GuardrailBlockedTopic
    {
        return DB::transaction(function () use ($pattern, $kind, $actor) {
            $row = GuardrailBlockedTopic::create([
                'pattern' => trim($pattern),
                'kind' => $kind,
                'enabled' => true,
                'created_by' => $actor->id,
            ]);
            $this->audit('blocked_topic_added', null, $kind.': '.trim($pattern), $actor, null);
            GuardrailPolicy::flush();

            return $row;
        });
    }

    /** Soft-disable a trigger (never a hard delete) + audit + flush. */
    public function disableBlockedTopic(GuardrailBlockedTopic $row, Admin $actor): GuardrailBlockedTopic
    {
        return DB::transaction(function () use ($row, $actor) {
            $row->forceFill(['enabled' => false, 'disabled_by' => $actor->id, 'disabled_at' => now()])->save();
            $this->audit('blocked_topic_disabled', $row->kind.': '.$row->pattern, null, $actor, 'soft-disabled (history retained)');
            GuardrailPolicy::flush();

            return $row->fresh();
        });
    }

    private function audit(string $field, mixed $old, mixed $new, Admin $actor, ?string $note): void
    {
        GuardrailConfigEvent::create([
            'field' => $field,
            'old_value' => $this->stringify($old),
            'new_value' => $this->stringify($new),
            'actor_id' => $actor->id,
            'note' => $note,
        ]);
    }

    private function stringify(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }

        return is_array($v) ? json_encode(array_values($v)) : (string) $v;
    }

    /** Normalize a value for change detection (arrays compared order-insensitively). */
    private function normalizeForCompare(mixed $v): string
    {
        if (is_array($v)) {
            $copy = array_values($v);
            sort($copy);

            return json_encode($copy) ?: '';
        }

        return $v === null ? '' : (string) $v;
    }

    private function normalize(string $s): string
    {
        $s = mb_strtolower(trim($s));

        return strtr($s, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
        ]);
    }
}
