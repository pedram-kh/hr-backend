<?php

namespace App\Models;

use App\Services\GeneralLaneCatalogue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One official page the general-knowledge lane may fetch (Slice 13c, plan.md §6). Admin-editable data; the domain
 * allowlist is NOT here (config/env, enforced at write by {@see GeneralLaneCatalogue} and at fetch by hr-ai).
 * `enabled = false` is a soft-disable (never a hard delete); `baseline` marks the rows seeded from `config/hr.php`.
 */
class GuardrailCataloguePage extends Model
{
    protected $fillable = ['slug', 'title', 'url', 'topics', 'enabled', 'baseline', 'created_by', 'updated_by'];

    protected $casts = [
        'topics' => 'array',
        'enabled' => 'boolean',
        'baseline' => 'boolean',
    ];

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by');
    }
}
