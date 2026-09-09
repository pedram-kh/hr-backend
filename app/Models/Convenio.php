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

    /**
     * Sprint 7f — every node of this convenio's group structure, both levels.
     * Filter with `->approved()` for anything user-facing; proposals are inert.
     */
    public function groups(): HasMany
    {
        return $this->hasMany(ConvenioGroup::class);
    }

    /** Top-level groups only, each with its sub-areas — the shape the picker renders. */
    public function groupTree(): HasMany
    {
        return $this->groups()->whereNull('parent_id')->with('children');
    }
}
