<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A document↔topic association with its own provenance (data-model §5). Each row
 * records HOW the topic was applied (`source`), with what `confidence`, and —
 * once a human confirms — who verified it and when. Sprint 3's bounded edit is
 * the FIRST writer of this table (human topic-tagging, `source = admin_manual`);
 * the AI tagging tier that writes `ai_agent` rows is Sprint 7.
 */
class DocumentTopic extends Model
{
    protected $fillable = [
        'document_id', 'topic_id', 'source', 'confidence', 'verified_by', 'verified_at',
    ];

    protected $casts = [
        'confidence' => 'float',
        'verified_at' => 'datetime',
    ];

    public function topic(): BelongsTo
    {
        return $this->belongsTo(Topic::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
