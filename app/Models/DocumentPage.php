<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentPage extends Model
{
    protected $fillable = [
        'document_id', 'page_number', 'text', 'image_path',
        // Sprint 7e (ADR-0026, review.md §2.4) — OCR provenance. extraction_source
        // defaults to 'text_layer' at the DB level for every existing/new row; the
        // other four are null until (and unless) this page is actually OCR'd.
        'extraction_source', 'ocr_quality', 'ocr_engine', 'ocr_cost_usd', 'ocr_bilingual',
    ];

    protected $casts = [
        'ocr_quality' => 'float',
        'ocr_cost_usd' => 'float',
        'ocr_bilingual' => 'boolean',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
