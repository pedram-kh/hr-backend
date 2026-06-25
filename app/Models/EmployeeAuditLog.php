<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The profile-change audit trail (data-model §7). One row per changed field, so
 * "what scope did this person have when the bot answered?" is answerable — the
 * dispute-defence record (ADR-0004). Written by EmployeeAuditLogger inside the
 * same transaction as the employee write (Sprint 5; schema existed since
 * Sprint 0 but had no writer until now).
 */
class EmployeeAuditLog extends Model
{
    protected $table = 'employee_audit_log';

    protected $fillable = [
        'employee_id', 'field_changed', 'old_value', 'new_value', 'changed_by', 'changed_at',
    ];

    protected $casts = [
        'changed_at' => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'changed_by');
    }
}
