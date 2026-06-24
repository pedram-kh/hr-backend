<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
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
        'reason', 'status', 'assigned_to', 'topic_id', 'resolved_at',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
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

    /** The originating employee `user` message that escalated (Sprint 4 board). */
    public function sourceMessage(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class, 'source_message_id');
    }

    /** The admin currently working the card (null = unassigned). */
    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'assigned_to');
    }

    public function topic(): BelongsTo
    {
        return $this->belongsTo(Topic::class);
    }

    /** The resolution (the flywheel link to a converted document), if resolved. */
    public function resolution(): HasOne
    {
        return $this->hasOne(EscalationResolution::class, 'card_id');
    }

    /** Append-only activity/audit timeline (Sprint 4). */
    public function events(): HasMany
    {
        return $this->hasMany(EscalationEvent::class, 'escalation_card_id')->orderBy('id');
    }
}
