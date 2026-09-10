<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sprint 8, Step 8 (plan.md §7) — one row per assistant message an employee
 * rated. `updateOrCreate(['message_id' => …], [...])` (unique on
 * `message_id`) is the ONLY writer — a second click replaces, never
 * duplicates (§7's own framing).
 */
class MessageFeedback extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'message_feedback';

    protected $fillable = ['message_id', 'employee_id', 'rating', 'comment'];

    public function message(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class, 'message_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
