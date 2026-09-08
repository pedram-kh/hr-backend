<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalaryTableRow extends Model
{
    protected $fillable = [
        'salary_table_id', 'job_category_id', 'gross_annual', 'base_salary_monthly',
        // The source header the monthly figure was read from, verbatim — what
        // the answer calls the figure, so "salario base" and "bruto mes" are
        // never both flattened into "salario mensual".
        'base_salary_monthly_label',
        // `pagas_count` is the payment count the SOURCE states (NULL when it
        // states none) — never a divisor, never assumed (Correction-salary-01).
        'extra_pay', 'pagas_count', 'hourly_rate', 'night_plus', 'raw_values',
    ];

    protected $casts = [
        'raw_values' => 'array',
    ];

    public function salaryTable(): BelongsTo
    {
        return $this->belongsTo(SalaryTable::class);
    }

    public function jobCategory(): BelongsTo
    {
        return $this->belongsTo(ConvenioJobCategory::class, 'job_category_id');
    }
}
