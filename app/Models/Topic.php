<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Managed descriptive vocabulary (data-model §4). Topics are seeded `approved`
 * from the FAQ categories; the AI may later PROPOSE new ones (Sprint 7). The
 * Knowledge Center (Sprint 3) only ever *picks* an existing `approved` topic to
 * tag a document — it never creates/renames/approves vocabulary (ADR-0011).
 */
class Topic extends Model
{
    protected $fillable = ['name', 'status', 'proposed_by', 'approved_by'];

    public function documents(): BelongsToMany
    {
        return $this->belongsToMany(Document::class, 'document_topics')
            ->withPivot(['source', 'confidence', 'verified_by', 'verified_at'])
            ->withTimestamps();
    }
}
