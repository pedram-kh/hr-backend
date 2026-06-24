<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The resolution of an escalation card, and the link to the knowledge article
 * it becomes (the flywheel — `converted_to_document_id`). Sprint 4 is the first
 * writer of this table (it existed schema-only since Sprint 0).
 *
 * `resolution_text` is stored whether or not the agent converts it to knowledge;
 * `converted_to_document_id` points at the `internal_hr_ruling` the conversion
 * produced. It is `null` on a resolve-without-convert. On "Save as knowledge" it
 * links the ruling document — which stays `draft` while the no-override conflict
 * fence blocks publish (so a retry reuses the same draft, not an orphan), and is
 * flipped to `active` once the scope-confirmed, conflict-clear ruling publishes.
 */
class EscalationResolution extends Model
{
    protected $fillable = [
        'card_id', 'resolved_by', 'resolution_text', 'converted_to_document_id',
    ];

    public function card(): BelongsTo
    {
        return $this->belongsTo(EscalationCard::class, 'card_id');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'resolved_by');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'converted_to_document_id');
    }
}
