<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only conversation access log (ADR-0018). Who viewed whose conversation,
 * when, and how. EVERY History access writes one — including a super_admin's.
 * No updated_at (append-only, never UPDATE/DELETE).
 */
class ConversationAccessLog extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'conversation_access_log';

    public const TYPE_VIEW = 'conversation_view';

    public const TYPE_SEARCH = 'history_search';

    protected $fillable = [
        'admin_id', 'employee_id', 'chat_session_id', 'access_type', 'context', 'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
