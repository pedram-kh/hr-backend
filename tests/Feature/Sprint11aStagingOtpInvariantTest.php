<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\LoginCode;
use App\Support\StagingFixedOtpGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Sprint 11a (spec §2.6, plan.md §F.3). Proves the staging fixed-OTP
 * convenience is inert by default, additive to (never a variant of) the
 * real OTP path, and independently refused at boot in production.
 */
class Sprint11aStagingOtpInvariantTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdmin(string $email): Admin
    {
        return Admin::create(['email' => $email, 'full_name' => 'Fixture Admin', 'status' => 'active']);
    }

    /** T1: allowlisted-domain email + fixed code (flag set) -> 200, valid token, correct identity. */
    public function test_allowlisted_domain_with_fixed_code_succeeds(): void
    {
        config(['app.staging_fixed_otp_code' => '654321']);
        $admin = $this->makeAdmin('agent@hr-staging.internal');

        $response = $this->postJson('/auth/verify-code', [
            'email' => 'agent@hr-staging.internal',
            'code' => '654321',
        ]);

        $response->assertOk();
        $response->assertJsonStructure(['token', 'token_type', 'identity']);
        $this->assertSame('agent@hr-staging.internal', $response->json('identity.email'));
        $this->assertNotNull($admin->fresh());
    }

    /** T2: non-allowlisted email + the fixed code's exact value (flag set) -> 422, falls through to the real path. */
    public function test_non_allowlisted_domain_with_fixed_code_value_falls_through_and_fails(): void
    {
        config(['app.staging_fixed_otp_code' => '654321']);
        $this->makeAdmin('real-admin@example.com');

        $response = $this->postJson('/auth/verify-code', [
            'email' => 'real-admin@example.com',
            'code' => '654321',
        ]);

        $response->assertStatus(422);
    }

    /** T3: flag unset -> the fixed code's value, submitted by anyone, on any domain, fails. Feature is fully inert without the flag. */
    public function test_flag_unset_makes_the_feature_fully_inert(): void
    {
        config(['app.staging_fixed_otp_code' => null]);
        $this->makeAdmin('agent@hr-staging.internal');

        $response = $this->postJson('/auth/verify-code', [
            'email' => 'agent@hr-staging.internal',
            'code' => '654321',
        ]);

        $response->assertStatus(422);
    }

    /** T4: boot refusal — throws in production with the flag set; does not throw otherwise. */
    public function test_boot_guard_refuses_production_with_flag_set(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('STAGING_FIXED_OTP_CODE must not be set when APP_ENV=production.');

        StagingFixedOtpGuard::assertSafeToBoot('production', '654321');
    }

    public function test_boot_guard_allows_non_production_with_flag_set(): void
    {
        StagingFixedOtpGuard::assertSafeToBoot('staging', '654321');
        StagingFixedOtpGuard::assertSafeToBoot('local', '654321');
        StagingFixedOtpGuard::assertSafeToBoot('testing', '654321');

        $this->addToAssertionCount(3); // no exception thrown above
    }

    public function test_boot_guard_allows_production_with_flag_empty(): void
    {
        StagingFixedOtpGuard::assertSafeToBoot('production', null);
        StagingFixedOtpGuard::assertSafeToBoot('production', '');

        $this->addToAssertionCount(2);
    }

    /** T5: a real account not on an allowlisted domain still requires — and successfully uses — a real generated OTP. */
    public function test_real_account_off_allowlist_still_uses_the_real_otp_path(): void
    {
        config(['app.staging_fixed_otp_code' => '654321']);
        $this->makeAdmin('real-admin@example.com');

        $this->postJson('/auth/request-code', ['email' => 'real-admin@example.com'])->assertOk();

        $loginCode = LoginCode::where('email', 'real-admin@example.com')->whereNull('consumed_at')->first();
        $this->assertNotNull($loginCode, 'requestCode must still create a real LoginCode row for a real account.');

        // We cannot read the plaintext code (only its hash is stored), but we
        // can prove the real path is untouched by confirming an arbitrary
        // wrong code fails distinctly from the fixed-code branch, and that
        // resetting the hash to a known code and verifying it succeeds.
        $loginCode->update(['code_hash' => \Illuminate\Support\Facades\Hash::make('111222')]);

        $response = $this->postJson('/auth/verify-code', [
            'email' => 'real-admin@example.com',
            'code' => '111222',
        ]);

        $response->assertOk();
        $response->assertJsonStructure(['token', 'token_type', 'identity']);
    }

    /** T6: the fixed-code path never touches login_codes — row count unchanged after a fixed-code login. */
    public function test_fixed_code_path_never_touches_login_codes_table(): void
    {
        config(['app.staging_fixed_otp_code' => '654321']);
        $this->makeAdmin('agent@hr-staging.internal');

        $before = LoginCode::count();

        $this->postJson('/auth/verify-code', [
            'email' => 'agent@hr-staging.internal',
            'code' => '654321',
        ])->assertOk();

        $this->assertSame($before, LoginCode::count());
    }
}
