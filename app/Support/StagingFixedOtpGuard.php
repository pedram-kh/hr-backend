<?php

namespace App\Support;

use RuntimeException;

/**
 * Sprint 11a (spec §2.6) — the boot-time half of the staging fixed-OTP
 * safety net. AuthController's verify-code branch is the runtime half (it
 * only ever matches an allowlisted-domain email); this is the independent
 * second guard: the app must refuse to even boot if the fixed code is
 * configured while running in production.
 *
 * Extracted to a static method (rather than inlined in
 * AppServiceProvider::boot()) specifically so it's unit-testable against
 * arbitrary environment/config combinations without booting a fresh
 * process per case (Sprint11aStagingOtpInvariantTest::T4).
 */
class StagingFixedOtpGuard
{
    public static function assertSafeToBoot(string $environment, ?string $stagingFixedOtpCode): void
    {
        if ($environment === 'production' && filled($stagingFixedOtpCode)) {
            throw new RuntimeException(
                'STAGING_FIXED_OTP_CODE must not be set when APP_ENV=production.'
            );
        }
    }
}
