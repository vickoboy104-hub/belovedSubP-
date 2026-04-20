<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CanonicalHost
{
    public function handle(Request $request, Closure $next): Response
    {
        if (app()->environment(['local', 'testing'])) {
            return $next($request);
        }

        if (!in_array($request->method(), ['GET', 'HEAD'], true)) {
            return $next($request);
        }

        $configuredHost = (string) env('APP_CANONICAL_HOST', '');
        if ($configuredHost === '') {
            $configuredHost = (string) parse_url((string) config('app.url'), PHP_URL_HOST);
        }
        if (!is_string($configuredHost) || $configuredHost === '') {
            return $next($request);
        }

        $currentHost = (string) $request->getHost();
        if ($currentHost === '' || strcasecmp($currentHost, $configuredHost) === 0) {
            return $next($request);
        }

        $scheme = parse_url((string) config('app.url'), PHP_URL_SCHEME);
        if (!is_string($scheme) || $scheme === '') {
            $scheme = $request->isSecure() ? 'https' : 'http';
        }

        $target = $scheme.'://'.$configuredHost.$request->getRequestUri();

        return redirect()->to($target, 301);
    }
}
