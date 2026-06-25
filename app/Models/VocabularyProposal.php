<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A proposal that a scoping-vocabulary value should exist (Sprint 7a, ADR-0011/
 * 0020). Generalizes the `topics` propose/approve pattern to territory/sector/
 * convenio. The AI can only PROPOSE (`proposed_by_source = ai_agent`); a human
 * APPROVES (folding into aliases — the default — or creating a new value). The
 * approve action is gated by `vocabulary.approve` (super_admin), who may
 * propose-and-approve in one step.
 */
class VocabularyProposal extends Model
{
    protected $fillable = [
        'facet', 'proposed_value',
        'variant_of_type', 'variant_of_id', 'variant_similarity',
        'resolution',
        'source_document_id', 'review_task_id',
        'status', 'proposed_by_source', 'proposed_by_admin_id', 'approved_by',
        'resolved_vocab_type', 'resolved_vocab_id', 'note',
    ];

    protected $casts = [
        'variant_similarity' => 'float',
    ];

    public function sourceDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'source_document_id');
    }

    public function reviewTask(): BelongsTo
    {
        return $this->belongsTo(DocumentReviewTask::class, 'review_task_id');
    }

    public function proposer(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'proposed_by_admin_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'approved_by');
    }
}
