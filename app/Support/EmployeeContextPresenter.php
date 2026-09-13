<?php

namespace App\Support;

use App\Models\Employee;

/**
 * Sprint 10b, Correction-02 (eyes-on finding). The EMPLEADO block shape —
 * name/email/territory/category-group/seniority (where recorded) — was built
 * once already for the escalation-card detail drawer
 * (`EscalationController::employeeContext()`, Sprint 10a Correction-02, CP-4
 * step 6/C2-2). The History conversation modal showed no employee context at
 * all beyond the header name, which this closes by giving both callers the
 * SAME shape from one place, rather than a second hand-copied version.
 *
 * A pure function: (employee) -> structured facts. No provider call, no
 * write, no ability check — the caller decides whether its own gate allows
 * showing this block at all (`EscalationController` gates it on
 * `escalation.work`; `HistoryController` gates its entire endpoint on
 * `history.view_all` already, so it needs no additional per-block check).
 *
 * `EscalationController::employeeContext()` itself is left untouched (not
 * rewired to call this) — it is already built, tested
 * (`Sprint10aCorrection02EmployeeContextTest`), and live; this Correction-02
 * only adds a second, independent caller so as not to risk that passing
 * suite for an unrelated fix.
 */
final class EmployeeContextPresenter
{
    /**
     * @return array{full_name:string, email:string, territory:?array{id:int,name:string}, job_category:?array{id:int,name:string}, convenio_group:?array{id:int,path_label:string}, seniority:?array{start_date:string,years:int}}
     */
    public static function present(Employee $employee): array
    {
        return [
            'full_name' => $employee->full_name,
            'email' => $employee->email,
            'territory' => $employee->territory !== null ? [
                'id' => $employee->territory->id,
                'name' => $employee->territory->name,
            ] : null,
            'job_category' => $employee->jobCategory !== null ? [
                'id' => $employee->jobCategory->id,
                'name' => $employee->jobCategory->name,
            ] : null,
            'convenio_group' => $employee->convenioGroup !== null ? [
                'id' => $employee->convenioGroup->id,
                'path_label' => $employee->convenioGroup->pathLabel(),
            ] : null,
            // "seniority where recorded" — `start_date` is nullable and often
            // absent on older/imported profiles; null here means not recorded,
            // never a guessed/derived date.
            'seniority' => $employee->start_date !== null ? [
                'start_date' => $employee->start_date->toDateString(),
                'years' => (int) $employee->start_date->diffInYears(now()),
            ] : null,
        ];
    }
}
