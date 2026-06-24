<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only activity/audit entry for an escalation card (Sprint 4): a status
 * move, an assignment, an agent reply, a resolution, a knowledge conversion, or
 * a blocked publish. Never updated or deleted — the card's history is permanent
 * (the same posture as `tag_events`).
 */
class EscalationEvent extends Model
{
    public const UPDATED_AT = null; // append-only — created_at only

    protected $fillable = [
        'escalation_card_id', 'type', 'old_value', 'new_value', 'actor_id', 'note',
    ];

    public function card(): BelongsTo
    {
        return $this->belongsTo(EscalationCard::class, 'escalation_card_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'actor_id');
    }
}
