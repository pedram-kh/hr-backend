<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sprint 7f (ADR-0028) — a proposed or approved "this job category belongs to
 * this group node" membership.
 *
 * Its only job is to PRE-FILL the group picker in the employee directory form
 * ("sugerido a partir de la categoría — confirma"). The matcher never reads it,
 * and a category with no membership is a normal steady state — 6 of the corpus's
 * 94 categories have a deterministic group, and convenio 21 has no categories at
 * all. It lives in its own table so a `salary:import` re-run cannot touch it.
 *
 * A partial unique index enforces one APPROVED membership per category; competing
 * proposals are free to coexist for a human to choose between.
 */
class ConvenioGroupCategory extends Model
{
    public const STATUS_NEEDS_REVIEW = 'needs_review';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const SOURCE_AI = 'ai_agent';

    public const SOURCE_MANUAL = 'admin_manual';

    protected $fillable = [
        'convenio_group_id', 'job_category_id', 'status', 'source', 'approved_by', 'approved_at',
    ];

    protected $casts = [
        'approved_at' => 'datetime',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(ConvenioGroup::class, 'convenio_group_id');
    }

    public function jobCategory(): BelongsTo
    {
        return $this->belongsTo(ConvenioJobCategory::class, 'job_category_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'approved_by');
    }
}
