<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

class Employee extends Model
{
    use HasApiTokens;

    protected $fillable = [
        'uuid', 'email', 'full_name', 'employee_external_id',
        'convenio_id', 'job_category_id', 'territory_id',
        'work_location', 'employment_type', 'start_date', 'status',
        'profile_last_reviewed_at',
    ];

    protected $casts = [
        'start_date' => 'date',
        'profile_last_reviewed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (Employee $employee) {
            if (empty($employee->uuid)) {
                $employee->uuid = (string) Str::uuid();
            }
        });
    }

    public function convenio(): BelongsTo
    {
        return $this->belongsTo(Convenio::class);
    }

    public function jobCategory(): BelongsTo
    {
        return $this->belongsTo(ConvenioJobCategory::class, 'job_category_id');
    }

    public function territory(): BelongsTo
    {
        return $this->belongsTo(Territory::class);
    }
}
