<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyPanelToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('services.panel.token'); // from .env PANEL_TOKEN
        $given = (string) $request->bearerToken();

        if (!hash_equals($expected, $given)) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        return $next($request);
    }
}