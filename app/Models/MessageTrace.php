<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The structured "how I got here" trace, one row per assistant turn. Doubles as
 * an eval/QA dataset (architecture.md §5). `trace` is a strict superset of the
 * Sprint-2a retrieval:probe shape: it adds guardrail, synthesis, floor-decision,
 * and authority_used blocks.
 */
class MessageTrace extends Model
{
    protected $fillable = [
        'message_id', 'trace',
    ];

    protected $casts = [
        'trace' => 'array',
    ];

    public function message(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class, 'message_id');
    }
}
