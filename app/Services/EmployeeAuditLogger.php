<?php

namespace App\Services;

use App\Models\Admin;
use App\Models\Employee;
use App\Models\EmployeeAuditLog;

/**
 * Writes the profile-change audit trail (data-model §7) — one row per changed
 * field, so the record answers "what scope did this person have when the bot
 * answered?" (ADR-0004, dispute defence). ALWAYS called inside the same
 * transaction as the employee write so the row and its audit never diverge.
 */
class EmployeeAuditLogger
{
    /** Fields whose change is legally/operationally meaningful and recorded. */
    public const TRACKED = [
        'email', 'full_name', 'employee_external_id',
        'convenio_id', 'job_category_id', 'territory_id',
        'work_location', 'employment_type', 'start_date', 'status',
        'profile_last_reviewed_at',
    ];

    /**
     * Snapshot the tracked fields of an employee (call BEFORE applying an edit),
     * so recordChanges() can diff against it after save.
     *
     * @return array<string, scalar|null>
     */
    public function snapshot(Employee $employee): array
    {
        $out = [];
        foreach (self::TRACKED as $field) {
            $out[$field] = $this->stringify($employee->getAttribute($field));
        }

        return $out;
    }

    /**
     * Diff the saved employee against an earlier snapshot; write one audit row
     * per changed tracked field. Returns the number of rows written.
     *
     * @param  array<string, scalar|null>  $before
     */
    public function recordChanges(Employee $employee, array $before, Admin $actor): int
    {
        $written = 0;
        foreach (self::TRACKED as $field) {
            $old = $before[$field] ?? null;
            $new = $this->stringify($employee->getAttribute($field));
            if ($old === $new) {
                continue;
            }
            $this->write($employee, $field, $old, $new, $actor);
            $written++;
        }

        return $written;
    }

    /**
     * Record an employee's creation: a `created` marker + the initial value of
     * each non-null tracked field (so a created profile has a provenance origin).
     */
    public function recordCreated(Employee $employee, Admin $actor): void
    {
        $this->write($employee, '*', null, 'created', $actor);
        foreach (self::TRACKED as $field) {
            $value = $this->stringify($employee->getAttribute($field));
            if ($value !== null) {
                $this->write($employee, $field, null, $value, $actor);
            }
        }
    }

    private function write(Employee $employee, string $field, ?string $old, ?string $new, Admin $actor): void
    {
        EmployeeAuditLog::create([
            'employee_id' => $employee->id,
            'field_changed' => $field,
            'old_value' => $old,
            'new_value' => $new,
            'changed_by' => $actor->id,
            'changed_at' => now(),
        ]);
    }

    /** Normalize a value to its stored string form (dates → ISO; null stays null). */
    private function stringify(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        return (string) $value;
    }
}
