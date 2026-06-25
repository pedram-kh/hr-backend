<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ConvenioJobCategory;
use App\Models\Employee;
use App\Models\EmployeeAuditLog;
use App\Services\EmployeeAuditLogger;
use App\Services\EmployeeCsvImporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The first-class employee directory (ADR-0004 — no HRIS/AD sync). Manual CRUD +
 * search/filter + CSV bootstrap. EVERY profile change writes employee_audit_log
 * (one row per changed field) inside the same transaction as the employee write
 * — the dispute-defence record. Scope fields are FK pickers into existing
 * vocabulary only (no vocabulary creation; ADR-0011). Editing email = changing
 * how the person logs in → a server confirm gate (409) plus the UI warning.
 *
 * The whole controller sits behind `ability:directory.manage` (super_admin +
 * hr_agent) — reads included (directory PII), per ADR-0018 / Q1.
 */
class EmployeeDirectoryController extends Controller
{
    public function __construct(private readonly EmployeeAuditLogger $audit) {}

    /** Searchable, filterable, paginated directory list. */
    public function index(Request $request): JsonResponse
    {
        $query = Employee::query()
            ->with(['convenio:id,numero,name,territory_id,sector_id', 'territory:id,code,name,level', 'jobCategory:id,name,group_code']);

        if ($request->filled('q')) {
            $q = trim((string) $request->string('q'));
            $query->where(function ($sub) use ($q) {
                $sub->where('full_name', 'ILIKE', "%{$q}%")
                    ->orWhere('email', 'ILIKE', "%{$q}%");
            });
        }
        if ($request->filled('convenio_id')) {
            $query->where('convenio_id', $request->integer('convenio_id'));
        }
        if ($request->filled('territory_id')) {
            $query->where('territory_id', $request->integer('territory_id'));
        }
        if ($request->filled('sector_id')) {
            $query->whereHas('convenio', fn ($c) => $c->where('sector_id', $request->integer('sector_id')));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        $employees = $query->orderBy('full_name')->paginate(50);
        $employees->getCollection()->transform(fn (Employee $e) => $this->listRow($e));

        return response()->json($employees);
    }

    /** Employee detail + the employee_audit_log timeline. */
    public function show(string $uuid): JsonResponse
    {
        $employee = Employee::with(['convenio:id,numero,name', 'territory:id,code,name,level', 'jobCategory:id,name,group_code'])
            ->where('uuid', $uuid)
            ->firstOrFail();

        $log = EmployeeAuditLog::where('employee_id', $employee->id)
            ->with('changedBy:id,full_name')
            ->orderByDesc('changed_at')
            ->orderByDesc('id')
            ->limit(500)
            ->get()
            ->map(fn (EmployeeAuditLog $r) => [
                'field_changed' => $r->field_changed,
                'old_value' => $r->old_value,
                'new_value' => $r->new_value,
                'changed_by' => $r->changedBy?->full_name,
                'changed_at' => $r->changed_at?->toIso8601String(),
            ]);

        return response()->json([
            'employee' => $this->detail($employee),
            'audit_log' => $log,
        ]);
    }

    /** Create one employee. Writes a created-baseline audit. */
    public function store(Request $request): JsonResponse
    {
        $data = $this->validatePayload($request, null);

        /** @var \App\Models\Admin $actor */
        $actor = $request->user();

        $employee = DB::transaction(function () use ($data, $actor) {
            $employee = Employee::create($this->writableFields($data));
            $this->audit->recordCreated($employee, $actor);

            return $employee;
        });

        return response()->json(['employee' => $this->detail($employee->fresh())], 201);
    }

    /**
     * Edit an employee. Diffs tracked fields → one audit row per change. Editing
     * the email requires `confirm_email_change=true` (409 otherwise): it changes
     * how the person logs in, so the change must be deliberate, not click-through.
     */
    public function update(string $uuid, Request $request): JsonResponse
    {
        $employee = Employee::where('uuid', $uuid)->firstOrFail();
        $data = $this->validatePayload($request, $employee);

        $newEmail = strtolower(trim((string) $data['email']));
        $emailChanges = $newEmail !== strtolower((string) $employee->email);
        if ($emailChanges && ! $request->boolean('confirm_email_change')) {
            return response()->json([
                'code' => 'email_change_confirmation_required',
                'message' => 'Cambiar el correo cambia cómo inicia sesión esta persona (es su clave de acceso). '
                    .'Confirma el cambio de correo para continuar.',
                'old_email' => $employee->email,
                'new_email' => $newEmail,
            ], 409);
        }

        /** @var \App\Models\Admin $actor */
        $actor = $request->user();

        $employee = DB::transaction(function () use ($employee, $data, $actor) {
            $before = $this->audit->snapshot($employee);
            $wasActive = $employee->status === 'active';
            $employee->fill($this->writableFields($data));
            $employee->save();
            $this->audit->recordChanges($employee, $before, $actor);

            // Deactivating an employee removes chat access immediately: revoke
            // outstanding tokens so a live session dies now (ADR-0018). The
            // EnsureActiveAccount gate + the OTP refusal cover re-entry.
            if ($wasActive && $employee->status === 'inactive') {
                $employee->tokens()->delete();
            }

            return $employee;
        });

        return response()->json(['employee' => $this->detail($employee->fresh())]);
    }

    /**
     * Mark the profile reviewed: set profile_last_reviewed_at = now (audited).
     * An explicit human attestation, distinct from "edited" — editing does NOT
     * bump it (Q9), so the staleness signal stays honest.
     */
    public function markReviewed(string $uuid, Request $request): JsonResponse
    {
        $employee = Employee::where('uuid', $uuid)->firstOrFail();

        /** @var \App\Models\Admin $actor */
        $actor = $request->user();

        $employee = DB::transaction(function () use ($employee, $actor) {
            $before = $this->audit->snapshot($employee);
            $employee->profile_last_reviewed_at = now();
            $employee->save();
            $this->audit->recordChanges($employee, $before, $actor);

            return $employee;
        });

        return response()->json(['employee' => $this->detail($employee->fresh())]);
    }

    /** CSV dry-run: per-row pass/fail report; writes nothing. */
    public function importValidate(Request $request, EmployeeCsvImporter $importer): JsonResponse
    {
        $rows = $this->readCsv($request);
        if ($rows === null) {
            return response()->json(['message' => 'Sube un archivo CSV (campo "file").'], 422);
        }

        return response()->json($importer->validate($rows));
    }

    /** CSV apply: import the VALID rows (each in its own transaction + audit). */
    public function import(Request $request, EmployeeCsvImporter $importer): JsonResponse
    {
        $rows = $this->readCsv($request);
        if ($rows === null) {
            return response()->json(['message' => 'Sube un archivo CSV (campo "file").'], 422);
        }

        /** @var \App\Models\Admin $actor */
        $actor = $request->user();

        return response()->json($importer->apply($rows, $actor));
    }

    /**
     * @return list<array<int|string, string>>|null
     */
    private function readCsv(Request $request): ?array
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
        ]);

        $path = $request->file('file')->getRealPath();
        $handle = $path !== false ? fopen($path, 'r') : false;
        if ($handle === false) {
            return null;
        }

        $rows = [];
        while (($line = fgetcsv($handle, 0, ',')) !== false) {
            $rows[] = $line;
        }
        fclose($handle);

        return $rows;
    }

    /**
     * Validate create/edit payload. job_category must belong to the chosen
     * convenio (FK pickers into existing vocabulary only).
     *
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request, ?Employee $existing): array
    {
        $emailUnique = Rule::unique('employees', 'email');
        if ($existing !== null) {
            $emailUnique = $emailUnique->ignore($existing->id);
        }

        $data = $request->validate([
            'email' => ['required', 'email', $emailUnique],
            'full_name' => ['required', 'string', 'max:255'],
            'employee_external_id' => ['nullable', 'string', 'max:255'],
            'convenio_id' => ['required', 'integer', 'exists:convenios,id'],
            'job_category_id' => ['nullable', 'integer', 'exists:convenio_job_categories,id'],
            'territory_id' => ['required', 'integer', 'exists:territories,id'],
            'work_location' => ['nullable', 'string', 'max:255'],
            'employment_type' => ['required', Rule::in(['full_time', 'part_time'])],
            'start_date' => ['nullable', 'date'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ]);

        // job_category must belong to the chosen convenio (no cross-convenio scope).
        if (! empty($data['job_category_id'])) {
            $belongs = ConvenioJobCategory::where('id', $data['job_category_id'])
                ->where('convenio_id', $data['convenio_id'])
                ->exists();
            if (! $belongs) {
                abort(response()->json([
                    'message' => 'La categoría profesional no pertenece al convenio seleccionado.',
                    'errors' => ['job_category_id' => ['La categoría no pertenece al convenio.']],
                ], 422));
            }
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function writableFields(array $data): array
    {
        return [
            'email' => strtolower(trim((string) $data['email'])),
            'full_name' => $data['full_name'],
            'employee_external_id' => $data['employee_external_id'] ?? null,
            'convenio_id' => $data['convenio_id'],
            'job_category_id' => $data['job_category_id'] ?? null,
            'territory_id' => $data['territory_id'],
            'work_location' => $data['work_location'] ?? null,
            'employment_type' => $data['employment_type'],
            'start_date' => $data['start_date'] ?? null,
            'status' => $data['status'] ?? 'active',
        ];
    }

    /** @return array<string, mixed> */
    private function listRow(Employee $e): array
    {
        return [
            'uuid' => $e->uuid,
            'full_name' => $e->full_name,
            'email' => $e->email,
            'status' => $e->status,
            'convenio' => $e->convenio ? ['id' => $e->convenio->id, 'numero' => $e->convenio->numero, 'name' => $e->convenio->name] : null,
            'territory' => $e->territory ? ['id' => $e->territory->id, 'code' => $e->territory->code, 'name' => $e->territory->name] : null,
            'job_category' => $e->jobCategory ? ['id' => $e->jobCategory->id, 'name' => $e->jobCategory->name] : null,
            'employment_type' => $e->employment_type,
            'profile_last_reviewed_at' => $e->profile_last_reviewed_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function detail(Employee $e): array
    {
        return [
            'uuid' => $e->uuid,
            'email' => $e->email,
            'full_name' => $e->full_name,
            'employee_external_id' => $e->employee_external_id,
            'convenio' => $e->convenio ? ['id' => $e->convenio->id, 'numero' => $e->convenio->numero, 'name' => $e->convenio->name] : null,
            'job_category' => $e->jobCategory ? ['id' => $e->jobCategory->id, 'name' => $e->jobCategory->name, 'group_code' => $e->jobCategory->group_code] : null,
            'territory' => $e->territory ? ['id' => $e->territory->id, 'code' => $e->territory->code, 'name' => $e->territory->name, 'level' => $e->territory->level] : null,
            'work_location' => $e->work_location,
            'employment_type' => $e->employment_type,
            'start_date' => $e->start_date?->toDateString(),
            'status' => $e->status,
            'profile_last_reviewed_at' => $e->profile_last_reviewed_at?->toIso8601String(),
            // Raw FK ids for the edit drawer's pickers.
            'convenio_id' => $e->convenio_id,
            'job_category_id' => $e->job_category_id,
            'territory_id' => $e->territory_id,
        ];
    }
}
