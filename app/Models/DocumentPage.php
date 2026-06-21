<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentPage extends Model
{
    protected $fillable = ['document_id', 'page_number', 'text', 'image_path'];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
