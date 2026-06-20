<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConvenioJobCategory extends Model
{
    protected $fillable = [
        'convenio_id', 'name', 'group_code', 'annual_hours', 'weekly_hours',
    ];

    public function convenio(): BelongsTo
    {
        return $this->belongsTo(Convenio::class);
    }
}
