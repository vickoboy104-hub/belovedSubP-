<?php

namespace App\Http\Middleware;

use App\Services\AdminActivityRecorder;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminMiddleware
{
    public function __construct(
        private readonly AdminActivityRecorder $recorder,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (!auth()->check() || !auth()->user()->is_admin) {
            // A refusal is the finding. It is written without the visitor's
            // details (no attempted address, nothing typed in the form) and at
            // most once per five minutes per address, so a probe loop shows up as
            // one traceable line rather than a thousand.
            $this->recorder->recordQuietly('denied', 'auth', 'admin.access_denied', 'Attempted to reach an administrator screen.', [
                'target' => ['route', (string) $request->route()?->getName()],
                'reason' => auth()->check() ? 'Signed in, but this account is not an administrator.' : 'Not signed in.',
                'throttle_seconds' => 300,
            ]);

            abort(403, 'Unauthorized');
        }

        return $next($request);
    }
}
