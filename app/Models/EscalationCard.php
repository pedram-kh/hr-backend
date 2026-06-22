<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Created when the pipeline can't answer (sensitive topic, weak/no retrieval,
 * off-domain). Sprint 2b-1 is DECIDE-AND-QUEUE only: cards are created with
 * status = new, assigned_to = null. The board that WORKS them is Sprint 4.
 */
class EscalationCard extends Model
{
    protected $fillable = [
        'uuid', 'chat_session_id', 'source_message_id', 'employee_id',
        'reason', 'status', 'assigned_to', 'topic_id',
    ];

    protected static function booted(): void
    {
        static::creating(function (EscalationCard $card) {
            if (empty($card->uuid)) {
                $card->uuid = (string) Str::uuid();
            }
        });
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(ChatSession::class, 'chat_session_id');
    }
}
