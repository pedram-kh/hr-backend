<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Convenio extends Model
{
    protected $fillable = [
        'numero', 'name', 'aliases', 'territory_id', 'sector_id',
        'annual_hours', 'weekly_hours', 'numero_a3', 'it_complement', 'notes',
    ];

    protected $casts = [
        'aliases' => 'array',
    ];

    public function territory(): BelongsTo
    {
        return $this->belongsTo(Territory::class);
    }

    public function sector(): BelongsTo
    {
        return $this->belongsTo(Sector::class);
    }

    public function jobCategories(): HasMany
    {
        return $this->hasMany(ConvenioJobCategory::class);
    }
}
