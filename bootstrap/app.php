<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withSchedule(function (Illuminate\Console\Scheduling\Schedule $schedule): void {
        $schedule->command('prices:sync')->hourly()->withoutOverlapping();
        $schedule->command('wallet:sync-deposits')->everyFiveMinutes()->withoutOverlapping();

        // A request that died between taking the money and filing the paper leaves
        // the customer paid and the agent short of the document. This is the only
        // thing that closes that gap on its own, so it runs often and never twice
        // at once.
        $schedule->command('manual:recover-documents')->everyFiveMinutes()->withoutOverlapping();
    })
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'is_admin' => \App\Http\Middleware\AdminMiddleware::class,
            'no_cache' => \App\Http\Middleware\NoCacheHeaders::class,
            'audit_owner' => \App\Http\Middleware\AuditOwnerAccess::class,
        ]);
        $middleware->web(prepend: [
            \App\Http\Middleware\CorrelationId::class,
        ], append: [
            \App\Http\Middleware\CanonicalHost::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // A form rejected by validation is an attempt, and attempts are half of what
        // an audit is for: the same field refused five times is a broken money
        // screen or somebody probing a privilege they do not have. Recorded for
        // administrator screens only, and only the names of the fields that failed -
        // never what was typed into them, which is where a password or an identity
        // number would otherwise end up in this table.
        $exceptions->render(function (Illuminate\Validation\ValidationException $e, Request $request) {
            $user = $request->user();

            if ($request->is('admin*') && $user !== null && (bool) $user->is_admin) {
                $route = (string) $request->route()?->getName();

                $category = match (true) {
                    str_contains($route, 'wallet') || str_contains($route, 'deposit') => 'wallet',
                    str_contains($route, 'users') => 'customer',
                    str_contains($route, 'orders') => 'order',
                    str_contains($route, 'settings') || str_contains($route, 'website') || str_contains($route, 'services') => 'platform',
                    str_contains($route, 'broadcast') => 'broadcast',
                    default => 'platform',
                };

                app(\App\Services\AdminActivityRecorder::class)->recordQuietly(
                    'failed',
                    $category,
                    'form.validation_failed',
                    'A form was rejected before it could run.',
                    [
                        'target' => ['route', $route],
                        'changes' => ['failed_fields' => array_keys($e->errors())],
                        'throttle_seconds' => 30,
                    ]
                );
            }

            // Nothing rendered here, so the normal validation response stands.
            return null;
        });

        $renderExpiredSession = function (Request $request) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Session expired. Refresh and try again.',
                ], 419);
            }

            if ($request->routeIs('login') || $request->is('login')) {
                return redirect()
                    ->route('login', absolute: false)
                    ->withErrors(['email' => 'Session expired. Please try logging in again.'])
                    ->withInput($request->except('password'));
            }

            return redirect()
                ->back()
                ->with('error', 'Your session expired. Please try again.');
        };

        $exceptions->render(function (TokenMismatchException $e, Request $request) use ($renderExpiredSession) {
            return $renderExpiredSession($request);
        });

        $exceptions->render(function (HttpExceptionInterface $e, Request $request) use ($renderExpiredSession) {
            if ($e->getStatusCode() !== 419) {
                return null;
            }

            return $renderExpiredSession($request);
        });
    })->create();
