<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetSessionCookieForHost
{
    public function handle(Request $request, Closure $next): Response
    {
        $host = strtolower((string) $request->getHost());

        // Keep staff/admin sessions isolated from client sessions.
        // This prevents CSRF 419 issues when SESSION_DOMAIN is configured too broadly
        // (e.g. .aiovpn.co.uk) and the same cookie is reused across subdomains.
        if (str_starts_with($host, 'panel.') || str_contains($host, 'panel')) {
            config([
                'session.cookie' => env('PANEL_SESSION_COOKIE', 'aiovpn_panel_session'),
                'session.domain' => null,
            ]);
        } else {
            config([
                'session.cookie' => env('CLIENT_SESSION_COOKIE', 'aiovpn_client_session'),
                'session.domain' => null,
            ]);
        }

        // Make sure the framework doesn't accidentally set an https-only cookie
        // when the request is actually http (common behind proxies / misconfigured APP_URL).
        $isHttps = $request->isSecure() || strtolower((string) $request->header('x-forwarded-proto')) === 'https';
        config(['session.secure' => $isHttps]);

        return $next($request);
    }
}
