<?php

namespace App\Http\Middleware;

use App\Services\SettingsService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * OriginGuard — CSRF protection based on Origin/Referer (originGuard in
 * middleware.ts): mutating methods must present an Origin (or Referer) whose
 * host matches the configured appUrl or the request host itself. Requests
 * without any origin header (server-to-server) are allowed through.
 */
class OriginGuard
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! in_array(strtoupper($request->getMethod()), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return $next($request);
        }

        $origin = (string) $request->headers->get('Origin', '');
        if ($origin === '') {
            $referer = (string) $request->headers->get('Referer', '');
            if ($referer === '') {
                return $next($request); // non-browser / server-to-server
            }
            $origin = $referer;
        }

        $host = parse_url($origin, PHP_URL_HOST);
        $appHost = parse_url(SettingsService::appUrl(), PHP_URL_HOST);

        $allowed = [$request->getHost()];
        if ($appHost) {
            $allowed[] = $appHost;
        }

        if ($host && in_array(strtolower($host), array_map('strtolower', array_filter($allowed)), true)) {
            return $next($request);
        }

        return response()->json(['error' => 'درخواست از مبدأ غیرمجاز رد شد.'], 403);
    }
}
