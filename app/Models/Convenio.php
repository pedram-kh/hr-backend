<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Convenio extends Model
{
    protected $fillable = [
        'numero', 'name', 'province_id', 'sector_id',
        'annual_hours', 'weekly_hours', 'numero_a3', 'it_complement', 'notes',
    ];

    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class);
    }

    public function sector(): BelongsTo
    {
        return $this->belongsTo(Sector::class);
    }
}
