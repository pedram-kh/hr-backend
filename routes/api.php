<?php

use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\Admin\AnswerModelController;
use App\Http\Controllers\Admin\CoverageGapController;
use App\Http\Controllers\Admin\DocumentController;
use App\Http\Controllers\Admin\EmployeeDirectoryController;
use App\Http\Controllers\Admin\EscalationController;
use App\Http\Controllers\Admin\GuardrailsController;
use App\Http\Controllers\Admin\HierarchyController;
use App\Http\Controllers\Admin\HistoryController;
use App\Http\Controllers\Admin\ReferenceFactController;
use App\Http\Controllers\Admin\ReviewQueueController;
use App\Http\Controllers\Admin\SandboxController;
use App\Http\Controllers\Admin\VocabularyController;
use App\Http\Controllers\Admin\VocabularyProposalController;
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

    /*
    | Sprint 7a — Messy-tail document intelligence (ADR-0011/0020). READS are
    | open to any admin (browse the AI review/expiry/proposal queues). WRITES
    | are gated: the AI re-suggest + the propose-vocabulary + the succession
    | handoff need knowledge.edit; APPROVING a vocabulary proposal into the
    | controlled vocabulary needs vocabulary.approve (super_admin). The AI itself
    | only proposes (the queued ProposeDocumentTags job); it never hits a route.
    */
    // Expiry queue (read) + the proposed-vocabulary list (read).
    Route::get('/review/expiry', [ReviewQueueController::class, 'expiry']);
    Route::get('/vocabulary-proposals', [VocabularyProposalController::class, 'index']);
    Route::get('/vocabulary-proposals/suggest', [VocabularyProposalController::class, 'suggest']);

    // knowledge.edit writes: re-run AI tagging, propose vocabulary, confirm a
    // succession handoff (the predecessor_document_id write).
    Route::middleware('ability:knowledge.edit')->group(function () {
        Route::post('/documents/{uuid}/resuggest', [DocumentController::class, 'resuggest']);
        Route::post('/vocabulary-proposals', [VocabularyProposalController::class, 'store']);
        Route::post('/review/expiry/{taskId}/resolve', [ReviewQueueController::class, 'resolveExpiry']);
    });

    // vocabulary.approve writes (super_admin): approve/reject a proposal into the
    // controlled vocabulary (fold into aliases / create a new value).
    Route::middleware('ability:vocabulary.approve')->group(function () {
        Route::post('/vocabulary-proposals/{id}/approve', [VocabularyProposalController::class, 'approve']);
        Route::post('/vocabulary-proposals/{id}/reject', [VocabularyProposalController::class, 'reject']);
    });

    /*
    | Sprint 7b-1 — Structured Reference Knowledge (ADR-0021). A NEW non-vectorized
    | scoped-fact type with the MANUAL create/verify path (no AI — the ai_agent
    | lane lights in 7b-2; no answering — that is 7c). READS are open to any admin
    | (auditor browses the facts + the reference-source content). WRITES (create,
    | edit, verify) are gated by knowledge.edit — reads open, writes gated, the
    | Sprint-3 posture. `sources`/`content` precede `{uuid}` so the literal path
    | wins. The verify route is SERVER-gated (knowledge.edit) by design — note the
    | carried document-confirm-route gap in review.md.
    */
    Route::get('/reference-facts', [ReferenceFactController::class, 'index']);
    Route::get('/reference-facts/sources', [ReferenceFactController::class, 'sources']);
    Route::get('/reference-facts/{uuid}', [ReferenceFactController::class, 'show']);
    Route::get('/reference-sources/{uuid}/content', [ReferenceFactController::class, 'sourceContent']);
    Route::middleware('ability:knowledge.edit')->group(function () {
        Route::post('/reference-facts', [ReferenceFactController::class, 'store']);
        Route::patch('/reference-facts/{uuid}', [ReferenceFactController::class, 'update']);
        Route::post('/reference-facts/{uuid}/verify', [ReferenceFactController::class, 'verify']);
        // Sprint 7b-2 (ADR-0022): reject an AI proposal (auditable, no delete);
        // (re-)run the segmentation agent on a reference source. The agent only
        // ever PROPOSES — it never hits the verify route (it cannot verify itself).
        Route::post('/reference-facts/{uuid}/reject', [ReferenceFactController::class, 'reject']);
        Route::post('/reference-sources/{uuid}/segment', [ReferenceFactController::class, 'segment']);
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

    /*
    | Sprint 6 — Guardrails configuration (ADR-0019). The admin layer ON TOP of
    | the hardcoded GuardrailService baseline. READ is open to any admin (auditor
    | browses read-only — oversight). WRITES are gated by guardrails.manage
    | (super_admin ONLY — the most safety-sensitive surface). The SERVER is the
    | boundary: a below-floor threshold is rejected (422, not clamped); the
    | baseline patterns are code, never editable; every change is audited to
    | guardrail_config_events. Additive / raise-only / stricter_of(baseline, admin).
    */
    Route::get('/guardrails', [GuardrailsController::class, 'index']);
    Route::middleware('ability:guardrails.manage')->group(function () {
        Route::post('/guardrails', [GuardrailsController::class, 'store']);
        Route::post('/guardrails/blocked-topics', [GuardrailsController::class, 'addBlockedTopic']);
        Route::delete('/guardrails/blocked-topics/{id}', [GuardrailsController::class, 'disableBlockedTopic']);
    });
});
