<?php

namespace App\Providers;

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
