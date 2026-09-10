<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Sprint 8, Step 6 (plan.md §6.1) — one row per turn drawn by the monthly
 * stratified quality sample. `verdict`/`failure_kind`/`note`/`reviewed_by`/
 * `reviewed_at` start null; `QualitySamplingService::recordVerdict()` is the
 * ONLY writer of those columns (one decision per turn, §6.3).
 */
class QualitySample extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'uuid', 'message_id', 'sampled_for_month', 'seed',
        'stratum_path', 'stratum_territory_id',
        'reviewed_by', 'verdict', 'failure_kind', 'note', 'reviewed_at',
        'escalation_card_id',
    ];

    protected $casts = [
        'reviewed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (QualitySample $sample) {
            if (empty($sample->uuid)) {
                $sample->uuid = (string) Str::uuid();
            }
        });
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class, 'message_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'reviewed_by');
    }

    public function stratumTerritory(): BelongsTo
    {
        return $this->belongsTo(Territory::class, 'stratum_territory_id');
    }

    public function escalationCard(): BelongsTo
    {
        return $this->belongsTo(EscalationCard::class);
    }
}
