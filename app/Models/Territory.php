<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Controlled vocabulary: a territorial scope. `level` distinguishes
 * national / regional / provincial (Sprint 1 restructure of `provinces`).
 * `parent_territory_id` is populated where obvious but carries NO precedence
 * logic this sprint (deferred to the scoping/RAG sprint).
 */
class Territory extends Model
{
    protected $fillable = ['code', 'name', 'level', 'parent_territory_id', 'aliases'];

    protected $casts = [
        'aliases' => 'array',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Territory::class, 'parent_territory_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Territory::class, 'parent_territory_id');
    }
}
