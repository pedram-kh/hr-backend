<?php

use App\Http\Controllers\AuthController;
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
