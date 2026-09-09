<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Sprint 7f (ADR-0028) — a node in a convenio's group structure.
 *
 * `parent_id IS NULL` → a GROUP ("Grupo 2"). `parent_id` set → a SUB-AREA of
 * that group ("área 5"), which exists only where the convenio prices the slices
 * differently. Depth is capped at two by a database trigger, so `parent` never
 * has a parent of its own and `children` is never nested.
 *
 * Only `approved` nodes are ever compared by the answer path or offered in the
 * directory picker; `needs_review` nodes are AI proposals awaiting a human and
 * are completely inert, the same way an unverified `reference_fact` is.
 */
class ConvenioGroup extends Model
{
    public const STATUS_NEEDS_REVIEW = 'needs_review';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUSES = [self::STATUS_NEEDS_REVIEW, self::STATUS_APPROVED, self::STATUS_REJECTED];

    public const SOURCE_AI = 'ai_agent';

    public const SOURCE_MANUAL = 'admin_manual';

    public const SOURCES = [self::SOURCE_AI, self::SOURCE_MANUAL];

    protected $fillable = [
        'convenio_id', 'parent_id', 'code_normalized', 'label', 'source_excerpt',
        'status', 'source', 'proposal_batch_id', 'approved_by', 'approved_at',
    ];

    protected $casts = [
        'approved_at' => 'datetime',
    ];

    public function convenio(): BelongsTo
    {
        return $this->belongsTo(Convenio::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'approved_by');
    }

    /** Categories attached to this node — a suggested default only; never read by the matcher. */
    public function jobCategories(): BelongsToMany
    {
        return $this->belongsToMany(ConvenioJobCategory::class, 'convenio_group_categories', 'convenio_group_id', 'job_category_id')
            ->withPivot(['status', 'source', 'approved_by', 'approved_at']);
    }

    /** Facts bound to this node. Many-to-many: one fact may span several nodes. */
    public function referenceFacts(): BelongsToMany
    {
        return $this->belongsToMany(ReferenceFact::class, 'reference_fact_group_scopes', 'convenio_group_id', 'reference_fact_id')
            ->withPivot(['bound_by', 'bound_at']);
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_APPROVED);
    }

    public function isSubArea(): bool
    {
        return $this->parent_id !== null;
    }

    /** "Grupo 2 › resto áreas" for a sub-area, "Grupo 2" for a group. The CSV/UI display form. */
    public function pathLabel(): string
    {
        return $this->parent_id !== null
            ? ($this->parent?->label ?? '?').' › '.$this->label
            : $this->label;
    }
}
