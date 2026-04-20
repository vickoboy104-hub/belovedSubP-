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
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'is_admin' => \App\Http\Middleware\AdminMiddleware::class,
            'no_cache' => \App\Http\Middleware\NoCacheHeaders::class,
        ]);
        $middleware->web(append: [
            \App\Http\Middleware\CanonicalHost::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
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
