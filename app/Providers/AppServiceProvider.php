<?php

namespace App\Providers;

use App\Support\StagingFixedOtpGuard;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureRateLimiters();

        // Sprint 11a (spec §2.6): refuse to boot in production if the
        // staging fixed-OTP convenience is configured. See
        // App\Support\StagingFixedOtpGuard.
        StagingFixedOtpGuard::assertSafeToBoot(
            app()->environment(),
            config('app.staging_fixed_otp_code'),
        );
    }

    private function configureRateLimiters(): void
    {
        // request-code: per-email — a ~60s min interval plus a 5/hour cap.
        RateLimiter::for('otp-request', function (Request $request) {
            $email = strtolower((string) $request->input('email'));

            return [
                Limit::perMinute(1)->by('otp-request:min:'.$email),
                Limit::perHour(5)->by('otp-request:hour:'.$email),
            ];
        });

        // verify-code: per email+IP — 10 attempts / 10 minutes (on top of the
        // per-row attempts cap enforced in the controller).
        RateLimiter::for('otp-verify', function (Request $request) {
            $key = strtolower((string) $request->input('email')).'|'.$request->ip();

            return Limit::perMinutes(10, 10)->by('otp-verify:'.$key);
        });
    }
}
