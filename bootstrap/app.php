<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: '',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // Sprint 8, Step 1 (ADR-0030) — the FIRST scheduler this codebase has ever
    // had. Every entry here is additive/read-mostly analytics work; nothing
    // scheduled touches the answer loop. Registered here (Laravel 11+'s
    // bootstrap-file scheduling) rather than a Console/Kernel.php, which this
    // app doesn't have. Run by a dedicated `hr-backend-scheduler` compose
    // service (`php artisan schedule:work` — ticks `schedule:run` every 60s
    // internally; same image as `hr-backend`/`hr-backend-worker`, just a
    // different `command:`, mirroring the worker's own precedent exactly).
    ->withSchedule(function (Schedule $schedule): void {
        // Proof-of-life, always first, always a no-op (see the command's own
        // doc comment) — "the scheduler is alive" must never be conflated
        // with "a real job succeeded."
        $schedule->command('schedule:heartbeat')->everyMinute();

        // Sprint 8 Step 3 — the analytics rollup (defaults to yesterday, safe
        // to re-run: delete-then-insert per date, per plan.md §2.4).
        $schedule->command('stats:rollup')->dailyAt('01:00')
            ->onOneServer()
            ->withoutOverlapping();

        // Sprint 8 Step 2 — the coverage-gap snapshot (plan.md §5.5).
        $schedule->command('coverage:snapshot')->dailyAt('01:15')
            ->onOneServer()
            ->withoutOverlapping();

        // Sprint 8 Step 5 — the nightly question-cluster job (plan.md §4.2).
        $schedule->command('questions:cluster')->dailyAt('01:30')
            ->onOneServer()
            ->withoutOverlapping();
    })
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'admin' => \App\Http\Middleware\EnsureAdmin::class,
            'ability' => \App\Http\Middleware\EnsureCan::class,
            // Sprint 8 (plan.md §9, §12 q7): Cobertura's OR-of-two-abilities gate.
            'coverage.view' => \App\Http\Middleware\EnsureCanViewCoverage::class,
            // Deactivation removes access immediately (ADR-0018): an inactive
            // admin/employee is refused on every authenticated request.
            'active' => \App\Http\Middleware\EnsureActiveAccount::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // API-only backend: always render exceptions as JSON.
        $exceptions->shouldRenderJsonWhen(fn () => true);

        // Unauthenticated requests get a JSON 401 (no web login route to redirect to).
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        });
    })->create();
