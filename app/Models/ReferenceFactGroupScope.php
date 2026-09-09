<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sprint 7f (ADR-0028) — one scope a reference fact applies to.
 *
 * A fact may have SEVERAL of these, because real facts are compound: staging's
 * verified fact #44 reads "Grupo 1 (todas las áreas) y Grupo 2 (área 5)" and
 * binds to two nodes. Tier 2 matches a fact when ANY of its bound scopes matches
 * the employee's node.
 *
 * A fact with no rows here is UNBOUND — not group-matchable, so a group-scoped
 * question escalates. That is the state all 88 facts on staging are in today.
 *
 * `bound_by` is always set by the service: binding is a human act, and there is
 * no automatic binder (the same rule 7d's resolution service follows).
 */
class ReferenceFactGroupScope extends Model
{
    protected $fillable = [
        'reference_fact_id', 'convenio_group_id', 'bound_by', 'bound_at',
    ];

    protected $casts = [
        'bound_at' => 'datetime',
    ];

    public function referenceFact(): BelongsTo
    {
        return $this->belongsTo(ReferenceFact::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(ConvenioGroup::class, 'convenio_group_id');
    }

    public function boundBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'bound_by');
    }
}
