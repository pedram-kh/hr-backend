<?php

use App\Http\Controllers\Admin\AnswerModelController;
use App\Http\Controllers\Admin\DocumentController;
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
    ->middleware('auth:sanctum');

/*
| Employee chat (Sprint 2b-1). One prose turn → scoped, cited answer or honest
| escalation. Employee-only (the controller rejects admins).
*/
Route::post('/chat/message', [ChatController::class, 'message'])
    ->middleware('auth:sanctum');

/*
| Admin knowledge-management API (Sprint 1). Admin-only (Sanctum + admin guard).
| Documents ingestion, the verification table/detail, and tag confirm/re-assign.
*/
Route::middleware(['auth:sanctum', 'admin'])->prefix('admin')->group(function () {
    Route::get('/documents', [DocumentController::class, 'index']);
    Route::post('/documents/upload', [DocumentController::class, 'upload']);
    Route::get('/documents/{uuid}', [DocumentController::class, 'show']);
    Route::post('/documents/{uuid}/confirm', [DocumentController::class, 'confirm']);
    Route::patch('/documents/{uuid}/facets/{facet}', [DocumentController::class, 'reassignFacet']);
    Route::get('/documents/{uuid}/pages/{page}/image', [DocumentController::class, 'pageImage']);
    Route::get('/vocabulary/{type}', [VocabularyController::class, 'index']);

    // Answer-model key handling (Sprint 2b-1, ADR-0015). super_admin enforced in
    // the controller. The raw key is never returned by any of these.
    Route::get('/answer-model/status', [AnswerModelController::class, 'status']);
    Route::post('/answer-model', [AnswerModelController::class, 'store']);
    Route::delete('/answer-model/key', [AnswerModelController::class, 'destroy']);
});
