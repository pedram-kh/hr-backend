<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentReviewTask extends Model
{
    /** Sprint 7d: the AI succession proposal is INERT until a human confirms. */
    public const PROPOSAL_PROPOSED = 'proposed';

    public const PROPOSAL_CONFIRMED = 'confirmed';

    public const PROPOSAL_REJECTED = 'rejected';

    protected $fillable = [
        'document_id', 'type', 'reason', 'raw_unmatched_values',
        'status', 'due_date', 'resolved_by', 'resolved_at',
        // Sprint 7d (ADR-0024/0020): the inert AI succession proposal. Writing
        // these changes NOTHING about retrievability or lineage — the AI never
        // writes documents.predecessor_document_id / retrieval_status, which is
        // exactly why the proposal lives on the TASK and not on the document.
        'ai_proposal', 'ai_proposal_status', 'ai_proposed_at',
    ];

    protected $casts = [
        'raw_unmatched_values' => 'array',
        'due_date' => 'date',
        'resolved_at' => 'datetime',
        'ai_proposal' => 'array',
        'ai_proposed_at' => 'datetime',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
