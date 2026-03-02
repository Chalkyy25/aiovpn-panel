<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureUserIsAdmin
{
    public function handle(Request $request, Closure $next)
    {
        // Not logged in? Send to Filament admin login.
        if (! auth()->check()) {
            return redirect()->route('filament.admin.auth.login');
        }

        // Logged in but not admin? Block.
        if (auth()->user()->role !== 'admin') {
            abort(403);
        }

        return $next($request);
    }
}