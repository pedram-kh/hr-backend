<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only audit entry for a guardrail-config change (Sprint 6, ADR-0019).
 * Never updated or deleted — the same posture as `escalation_events` /
 * `tag_events`. A rejected below-floor write never reaches here (nothing
 * changed); only an accepted change is recorded.
 */
class GuardrailConfigEvent extends Model
{
    public const UPDATED_AT = null; // append-only — created_at only

    protected $fillable = [
        'field', 'old_value', 'new_value', 'actor_id', 'note',
    ];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'actor_id');
    }
}
