<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Single-row global guardrail config (Sprint 6, ADR-0019). id = 1, created on
 * first access (the same shape as AnswerModelSetting). Holds the ADMIN layer of
 * the two-layer guardrail model (architecture.md §7); the hardcoded baseline
 * lives in GuardrailService + config/hr.php and is NOT here.
 *
 * RAISE-ONLY: the threshold columns are nullable overrides — null means "use the
 * hardcoded floor". The combination math (max / union / intersection) is NOT in
 * this model: it lives in GuardrailPolicy, which never hands a caller a value
 * weaker than the baseline. This model is just typed storage + the blocked-topic
 * relation. All writes go through GuardrailConfigService (validated + audited).
 */
class GuardrailConfig extends Model
{
    protected $table = 'guardrail_config';

    protected $fillable = [
        'retrieval_score_floor',
        'answer_confidence_floor',
        'router_confidence_floor',
        'off_domain_message',
        'tone_constraints',
        'convert_allowed_reasons',
        'updated_by',
    ];

    protected $casts = [
        'retrieval_score_floor' => 'float',
        'answer_confidence_floor' => 'float',
        'router_confidence_floor' => 'float',
        'convert_allowed_reasons' => 'array',
    ];

    /**
     * The single, global config row, created on first access. We match on "the
     * one row" rather than a literal id = 1: `id` is not fillable, so pinning the
     * lookup to id = 1 would (once an auto-increment sequence has advanced) keep
     * inserting fresh rows instead of reusing the existing one. firstOrCreate([])
     * returns the existing row regardless of its id, and creates exactly one when
     * the table is empty.
     */
    public static function current(): self
    {
        return static::query()->firstOrCreate([]);
    }
}
