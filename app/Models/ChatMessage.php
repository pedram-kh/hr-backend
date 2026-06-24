<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ChatMessage extends Model
{
    protected $fillable = [
        'session_id', 'role', 'content', 'author_admin_id',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(ChatSession::class, 'session_id');
    }

    /** The admin who authored an `hr_agent` (human) reply; null for bot/employee turns (Sprint 4). */
    public function author(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'author_admin_id');
    }

    public function citations(): HasMany
    {
        return $this->hasMany(MessageCitation::class, 'message_id');
    }

    public function trace(): HasOne
    {
        return $this->hasOne(MessageTrace::class, 'message_id');
    }
}
