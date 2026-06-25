<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One admin-added blocked-topic / off-domain trigger (Sprint 6, ADR-0019). The
 * ADD-ONLY half of knobs 2 and 3: this table holds ONLY admin-added triggers;
 * the hardcoded GuardrailService baseline is code, not data, so it can never be
 * removed here. "Disable" is a soft flag (never a hard delete) — history intact.
 *
 * `kind`: blocked_topic → escalate `sensitive_topic`; off_domain → escalate
 * `off_domain` (the narrow-only boundary). `pattern` is matched by GuardrailPolicy
 * as an escaped, accent-insensitive, word-boundary LITERAL — never raw regex.
 */
class GuardrailBlockedTopic extends Model
{
    public const KIND_BLOCKED_TOPIC = 'blocked_topic';

    public const KIND_OFF_DOMAIN = 'off_domain';

    protected $fillable = [
        'pattern', 'kind', 'enabled', 'created_by', 'disabled_by', 'disabled_at',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'disabled_at' => 'datetime',
    ];

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by');
    }
}
