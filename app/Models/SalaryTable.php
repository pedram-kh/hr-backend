<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalaryTable extends Model
{
    protected $fillable = [
        'convenio_id', 'year', 'validity_start', 'validity_end', 'source_document_id',
    ];

    protected $casts = [
        'validity_start' => 'date',
        'validity_end' => 'date',
    ];

    public function convenio(): BelongsTo
    {
        return $this->belongsTo(Convenio::class);
    }

    public function sourceDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'source_document_id');
    }

    public function rows(): HasMany
    {
        return $this->hasMany(SalaryTableRow::class);
    }
}
