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
     * Sprint 11a (spec §2.6): domains eligible for the staging fixed-OTP
     * convenience — matches the seeded test accounts
     * (docker-compose.staging.yml SEED_*_EMAIL). Real accounts on any other
     * domain are never affected: the branch below only matches when BOTH
     * the flag is set AND the email's domain is in this list, so this const
     * being present in the codebase changes nothing unless
     * STAGING_FIXED_OTP_CODE is also non-empty.
     *
     * @var list<string>
     */
    private const STAGING_FIXED_OTP_ALLOWED_DOMAINS = [
        'hr-staging.internal',
    ];

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

        // Sprint 11a (spec §2.6): staging fixed-OTP convenience — additive,
        // BEFORE the real LoginCode lookup, and skips it entirely (no row
        // read, no row written, no attempts counter touched — T6). Only
        // matches when the flag is configured AND the email's domain is
        // allowlisted; any other request falls straight through to the
        // unmodified real OTP path below, exactly as it did before this
        // branch existed.
        $stagingFixedOtp = $this->tryStagingFixedOtp($email, $data['code']);
        if ($stagingFixedOtp !== null) {
            return $stagingFixedOtp;
        }

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
     * Sprint 11a (spec §2.6). Returns a ready response if the fixed-OTP
     * convenience matched (success or a deliberate non-match 422 would be
     * wrong here — non-matches must fall through, not respond), else null
     * to let the caller continue to the real LoginCode path.
     */
    private function tryStagingFixedOtp(string $email, string $submittedCode): ?JsonResponse
    {
        $fixedCode = config('app.staging_fixed_otp_code');
        if (blank($fixedCode)) {
            return null;
        }

        $domain = substr($email, strrpos($email, '@') + 1);
        if (! in_array($domain, self::STAGING_FIXED_OTP_ALLOWED_DOMAINS, true)) {
            return null;
        }

        if (! hash_equals((string) $fixedCode, $submittedCode)) {
            return null;
        }

        // Domain is allowlisted AND flag is set AND code matches: this is
        // the one branch that resolves without ever touching login_codes.
        // Account resolution/active-status rules are unchanged — an
        // inactive or unknown account still fails here exactly as the real
        // path would.
        [$account, $accountType] = $this->resolveAccount($email);
        if ($account === null) {
            return response()->json(['message' => 'Invalid or expired code.'], 422);
        }

        $token = $account->createToken('otp-login')->plainTextToken;

        return response()->json([
            'token' => $token,
            'token_type' => 'Bearer',
            'identity' => IdentityPresenter::present($account, $accountType),
        ]);
    }

    /**
     * @return array{0: Employee|Admin|null, 1: string|null}
     */
    private function resolveAccount(string $email): array
    {
        // Inactive accounts cannot log in (ADR-0018): a deactivated admin OR
        // employee is treated as not-registered, so no code is ever sent.
        if ($employee = Employee::where('email', $email)->where('status', 'active')->first()) {
            return [$employee, 'employee'];
        }

        if ($admin = Admin::where('email', $email)->where('status', 'active')->first()) {
            return [$admin, 'admin'];
        }

        return [null, null];
    }

    private function findAccount(string $email, string $accountType): Employee|Admin|null
    {
        // Same active-status gate at verification: a code that pre-dates a
        // deactivation cannot be redeemed into a token.
        return $accountType === 'admin'
            ? Admin::where('email', $email)->where('status', 'active')->first()
            : Employee::where('email', $email)->where('status', 'active')->first();
    }
}
