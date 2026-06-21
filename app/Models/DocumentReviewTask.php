<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentReviewTask extends Model
{
    protected $fillable = [
        'document_id', 'type', 'reason', 'raw_unmatched_values',
        'status', 'due_date', 'resolved_by', 'resolved_at',
    ];

    protected $casts = [
        'raw_unmatched_values' => 'array',
        'due_date' => 'date',
        'resolved_at' => 'datetime',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
