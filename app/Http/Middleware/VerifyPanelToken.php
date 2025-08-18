<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class VerifyPanelToken
{
    public function handle(Request $request, Closure $next)
    {
        $hdr = $request->header('X-Panel-Token');
        if (!hash_equals((string) config('services.panel.token'), (string) $hdr)) {
            abort(401, 'bad token');
        }
        return $next($request);
    }
}