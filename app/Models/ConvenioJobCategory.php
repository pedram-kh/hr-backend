<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ConvenioJobCategory extends Model
{
    protected $fillable = [
        'convenio_id', 'name', 'group_code', 'annual_hours', 'weekly_hours',
    ];

    public function convenio(): BelongsTo
    {
        return $this->belongsTo(Convenio::class);
    }

    /**
     * Sprint 7f — the group node(s) this category has been proposed for or
     * approved into. At most one may be `approved` (a partial unique index), so
     * the directory's suggested default is never ambiguous.
     *
     * `group_code` on this model is deliberately NOT the source of any of this:
     * it is salary-spreadsheet layout provenance, and Sprint 7f neither migrates
     * nor reads it (ADR-0028). Membership comes from convenio text via a
     * human-approved proposal, which is why it lives in its own table and
     * `salary:import` can never disturb it.
     */
    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(ConvenioGroup::class, 'convenio_group_categories', 'job_category_id', 'convenio_group_id')
            ->withPivot(['status', 'source', 'approved_by', 'approved_at']);
    }
}
