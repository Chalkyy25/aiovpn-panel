<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureUserIsReseller
{
    public function handle(Request $request, Closure $next)
    {
        // Not logged in? Send to Filament reseller login.
        if (! auth()->check()) {
            return redirect()->route('filament.reseller.auth.login');
        }

        // Logged in but not reseller? Block.
        if (auth()->user()->role !== 'reseller') {
            abort(403);
        }

        return $next($request);
    }
}