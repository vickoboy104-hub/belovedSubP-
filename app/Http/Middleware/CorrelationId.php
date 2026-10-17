<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Puts one identifier on every request so the audit trail can be read back as a
 * story: a refused attempt, the correction, then the action that went through
 * share a correlation id, and the log shows that they were one sitting rather
 * than three unrelated events.
 */
class CorrelationId
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set('correlation_id', (string) Str::uuid());

        return $next($request);
    }
}
