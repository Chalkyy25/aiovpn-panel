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

        $panelHost = strtolower((string) config('aio.control_plane_host', 'panel.aiovpn.co.uk'));
        $isPanelHost = $host === $panelHost || str_ends_with($host, '.'.$panelHost);

        $panelCookie = (string) config('aio.session_cookies.panel', 'aiovpn_panel_session');
        $clientCookie = (string) config('aio.session_cookies.client', 'aiovpn_client_session');

        // Keep staff/admin sessions isolated from client sessions.
        // This prevents CSRF 419 issues when SESSION_DOMAIN is configured too broadly
        // (e.g. .aiovpn.co.uk) and the same cookie is reused across subdomains.
        if ($isPanelHost || str_starts_with($host, 'panel.')) {
            config([
                'session.cookie' => $panelCookie,
                'session.domain' => null,
            ]);
        } else {
            config([
                'session.cookie' => $clientCookie,
                'session.domain' => null,
            ]);
        }

        // Make sure the framework doesn't accidentally set an https-only cookie
        // when the request is actually http (common behind proxies / misconfigured APP_URL).
        $forwardedProto = strtolower((string) $request->header('x-forwarded-proto', ''));
        if (str_contains($forwardedProto, ',')) {
            $forwardedProto = trim(explode(',', $forwardedProto)[0]);
        }

        $isHttps = $request->isSecure() || $forwardedProto === 'https';
        config(['session.secure' => $isHttps]);

        return $next($request);
    }
}
