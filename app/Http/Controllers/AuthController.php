<?php

namespace App\Http\Controllers;

use App\Mail\LoginCodeMail;
use App\Models\Admin;
use App\Models\Employee;
use App\Models\LoginCode;
use App\Services\IdentityPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

class AuthController extends Controller
{
    private const CODE_TTL_MINUTES = 10;

    private const MAX_VERIFY_ATTEMPTS = 5;

    /**
     * Request an email OTP. Always returns a generic 200 so the endpoint never
     * reveals whether an email is registered.
     */
    public function requestCode(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $email = strtolower(trim($data['email']));
        [$account, $accountType] = $this->resolveAccount($email);

        if ($account !== null) {
            // Invalidate any outstanding codes for this email (single live code).
            LoginCode::query()
                ->where('email', $email)
                ->whereNull('consumed_at')
                ->update(['consumed_at' => now()]);

            $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

            LoginCode::create([
                'account_type' => $accountType,
                'email' => $email,
                'code_hash' => Hash::make($code), // hash only — never store plaintext
                'expires_at' => now()->addMinutes(self::CODE_TTL_MINUTES),
                'attempts' => 0,
            ]);

            // Synchronous send (review C4): no queue worker; appears in MailHog at once.
            Mail::to($email)->send(new LoginCodeMail($code, self::CODE_TTL_MINUTES));
        }

        return response()->json([
            'message' => 'If that email is registered, a login code has been sent.',
        ]);
    }

    /**
     * Verify an email OTP and, on success, issue a ~24h Sanctum bearer token.
     */
    public function verifyCode(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'code' => ['required', 'string'],
        ]);

        $email = strtolower(trim($data['email']));

        $loginCode = LoginCode::query()
            ->where('email', $email)
            ->whereNull('consumed_at')
            ->orderByDesc('id')
            ->first();

        $invalid = fn () => response()->json(['message' => 'Invalid or expired code.'], 422);

        if ($loginCode === null) {
            return $invalid();
        }

        if (Carbon::parse($loginCode->expires_at)->isPast()) {
            $loginCode->update(['consumed_at' => now()]);

            return $invalid();
        }

        // Count this attempt; cap brute-force.
        $loginCode->increment('attempts');
        if ($loginCode->attempts > self::MAX_VERIFY_ATTEMPTS) {
            $loginCode->update(['consumed_at' => now()]);

            return response()->json(['message' => 'Too many attempts. Request a new code.'], 429);
        }

        if (! Hash::check($data['code'], $loginCode->code_hash)) {
            return $invalid();
        }

        // Success: single-use.
        $loginCode->update(['consumed_at' => now()]);

        $account = $this->findAccount($email, $loginCode->account_type);
        if ($account === null) {
            return $invalid();
        }

        $token = $account->createToken('otp-login')->plainTextToken;

        return response()->json([
            'token' => $token,
            'token_type' => 'Bearer',
            'identity' => IdentityPresenter::present($account, $loginCode->account_type),
        ]);
    }

    /**
     * @return array{0: Employee|Admin|null, 1: string|null}
     */
    private function resolveAccount(string $email): array
    {
        if ($employee = Employee::where('email', $email)->first()) {
            return [$employee, 'employee'];
        }

        if ($admin = Admin::where('email', $email)->first()) {
            return [$admin, 'admin'];
        }

        return [null, null];
    }

    private function findAccount(string $email, string $accountType): Employee|Admin|null
    {
        return $accountType === 'admin'
            ? Admin::where('email', $email)->first()
            : Employee::where('email', $email)->first();
    }
}
