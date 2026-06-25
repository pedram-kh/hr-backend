<?php

use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\Admin\AnswerModelController;
use App\Http\Controllers\Admin\CoverageGapController;
use App\Http\Controllers\Admin\DocumentController;
use App\Http\Controllers\Admin\EmployeeDirectoryController;
use App\Http\Controllers\Admin\EscalationController;
use App\Http\Controllers\Admin\HierarchyController;
use App\Http\Controllers\Admin\HistoryController;
use App\Http\Controllers\Admin\SandboxController;
use App\Http\Controllers\Admin\VocabularyController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\MeController;
use Illuminate\Support\Facades\Route;

/*
| Email-OTP auth (no passwords, no SSO — ADR-0003). Routes are unprefixed
| (apiPrefix '') so they match the spec: /auth/*, /me.
*/

Route::post('/auth/request-code', [AuthController::class, 'requestCode'])
    ->middleware('throttle:otp-request');

Route::post('/auth/verify-code', [AuthController::class, 'verifyCode'])
    ->middleware('throttle:otp-verify');

Route::get('/me', [MeController::class, 'show'])
    ->middleware(['auth:sanctum', 'active']);

/*
| Employee chat (Sprint 2b-1). One prose turn → scoped, cited answer or honest
| escalation. Employee-only (the controller rejects admins). The `active` gate
| means a deactivated employee can't chat (ADR-0018).
*/
Route::post('/chat/message', [ChatController::class, 'message'])
    ->middleware(['auth:sanctum', 'active']);

// Sprint 4 (Q-D): load the caller's own most-recent session so the employee
// sees a human (hr_agent) reply land in the chat (hydrate on mount + poll).
// Self-scoped; employee-only. No session list/picker (that is Sprint 5).
Route::get('/chat/session', [ChatController::class, 'session'])
    ->middleware(['auth:sanctum', 'active']);

/*
| Admin knowledge-management API (Sprint 1). Admin-only (Sanctum + admin guard).
| Documents ingestion, the verification table/detail, and tag confirm/re-assign.
*/
Route::middleware(['auth:sanctum', 'admin', 'active'])->prefix('admin')->group(function () {
    Route::get('/documents', [DocumentController::class, 'index']);
    Route::post('/documents/upload', [DocumentController::class, 'upload']);
    Route::get('/documents/{uuid}', [DocumentController::class, 'show']);
    Route::post('/documents/{uuid}/confirm', [DocumentController::class, 'confirm']);
    Route::get('/documents/{uuid}/pages/{page}/image', [DocumentController::class, 'pageImage']);
    Route::get('/vocabulary/{type}', [VocabularyController::class, 'index']);
    // Convenio-scoped job categories for the directory FK picker (Sprint 5).
    Route::get('/job-categories', [VocabularyController::class, 'jobCategories']);

    /*
    | Sprint 5 — Employee directory (ADR-0004). CRUD + search/filter + CSV
    | bootstrap. Behind directory.manage (super_admin + hr_agent), reads
    | included (directory PII). EVERY change writes employee_audit_log; editing
    | email requires confirm_email_change (409). FK pickers into existing
    | vocabulary only.
    */
    Route::middleware('ability:directory.manage')->group(function () {
        Route::get('/employees', [EmployeeDirectoryController::class, 'index']);
        Route::post('/employees', [EmployeeDirectoryController::class, 'store']);
        Route::get('/employees/{uuid}', [EmployeeDirectoryController::class, 'show']);
        Route::patch('/employees/{uuid}', [EmployeeDirectoryController::class, 'update']);
        Route::post('/employees/{uuid}/mark-reviewed', [EmployeeDirectoryController::class, 'markReviewed']);
        Route::post('/employees/import/validate', [EmployeeDirectoryController::class, 'importValidate']);
        Route::post('/employees/import', [EmployeeDirectoryController::class, 'import']);
    });

    /*
    | Sprint 5 — Admin & role management. Behind admin.manage (super_admin ONLY):
    | creating an admin / granting history.view_all is the most privileged action
    | (ADR-0018). Roles via spatie syncRoles; deactivation revokes tokens.
    */
    Route::middleware('ability:admin.manage')->group(function () {
        Route::get('/admins', [AdminController::class, 'index']);
        Route::post('/admins', [AdminController::class, 'store']);
        Route::patch('/admins/{uuid}', [AdminController::class, 'update']);
        Route::put('/admins/{uuid}/roles', [AdminController::class, 'syncRoles']);
    });

    /*
    | Sprint 5 — The gated full-History browser + search (ADR-0018). Behind
    | history.view_all (super_admin + auditor ONLY). The SERVER is the boundary:
    | an hr_agent / knowledge_editor hitting these directly 403s. EVERY
    | conversation access writes conversation_access_log (incl. super_admin).
    */
    Route::middleware('ability:history.view_all')->group(function () {
        Route::get('/history/conversations', [HistoryController::class, 'index']);
        Route::get('/history/conversations/{sessionUuid}', [HistoryController::class, 'show']);
        Route::get('/history/employees/{employeeUuid}', [HistoryController::class, 'employee']);
        Route::get('/history/search', [HistoryController::class, 'search']);
    });

    /*
    | Sprint 3 — Knowledge Center. READS are open to any admin (an auditor
    | browses + inspects + runs the read-only sandbox). WRITES are gated by the
    | knowledge.edit ability (super_admin + knowledge_editor only).
    */
    // Lens hierarchy + coverage gaps + the real-document viewer + the sandbox (reads).
    Route::get('/hierarchy', [HierarchyController::class, 'roots']);
    Route::get('/hierarchy/children', [HierarchyController::class, 'children']);
    Route::get('/coverage-gaps', [CoverageGapController::class, 'index']);
    Route::get('/documents/{uuid}/source', [DocumentController::class, 'source']);
    Route::post('/documents/{uuid}/sandbox', [SandboxController::class, 'run']);

    // Bounded edit (writes) — knowledge.edit only. Each appends append-only
    // admin_manual provenance; scope-affecting saves require confirm_scope_change.
    Route::middleware('ability:knowledge.edit')->group(function () {
        Route::patch('/documents/{uuid}/facets/{facet}', [DocumentController::class, 'reassignFacet']);
        Route::patch('/documents/{uuid}', [DocumentController::class, 'updateLifecycle']);
        Route::post('/documents/{uuid}/topics', [DocumentController::class, 'addTopic']);
        Route::delete('/documents/{uuid}/topics/{topicId}', [DocumentController::class, 'removeTopic']);
    });

    // Answer-model key handling (Sprint 2b-1, ADR-0015). super_admin enforced in
    // the controller. The raw key is never returned by any of these.
    Route::get('/answer-model/status', [AnswerModelController::class, 'status']);
    Route::post('/answer-model', [AnswerModelController::class, 'store']);
    Route::delete('/answer-model/key', [AnswerModelController::class, 'destroy']);

    /*
    | Sprint 4 — Escalation board + the flywheel. READS (board list + card
    | detail incl. the card-scoped conversation + trace) are open to any admin
    | (an auditor browses read-only). WRITES (assign/move/reply/resolve/publish)
    | are gated by the escalation.work ability (super_admin + hr_agent). Every
    | write is audited to escalation_events; the no-override rule is enforced at
    | publish (block/re-escalate), never advisory.
    */
    Route::get('/escalations', [EscalationController::class, 'index']);
    Route::get('/escalations/{uuid}', [EscalationController::class, 'show']);
    Route::middleware('ability:escalation.work')->group(function () {
        Route::patch('/escalations/{uuid}', [EscalationController::class, 'update']);
        Route::post('/escalations/{uuid}/reply', [EscalationController::class, 'reply']);
        Route::post('/escalations/{uuid}/resolve', [EscalationController::class, 'resolve']);
    });
});
