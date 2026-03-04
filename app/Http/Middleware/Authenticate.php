<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

class Authenticate extends Middleware
{
    /**
     * Get the path the user should be redirected to when they are not authenticated.
     */
    protected function redirectTo(Request $request): ?string
    {
        if ($request->expectsJson()) {
            return null;
        }

        // Client portal (client guard) lives at /login + /dashboard + /downloads
        if (
            $request->routeIs('client.*') ||
            $request->is('login', 'client/login', 'dashboard', 'downloads', 'vpn/*/download')
        ) {
            return route('client.login.form');
        }

        // Staff / legacy web pages should go to the staff login.
        if ($request->is('legacy/*', 'staff/*') || $request->routeIs('profile.*')) {
            return Route::has('staff.login.form')
                ? route('staff.login.form')
                : '/staff/login';
        }

        // If a conventional web login exists (e.g. Fortify/Breeze), use it.
        if (Route::has('login')) {
            return route('login');
        }

        // Safe fallback.
        return route('client.login.form');
    }
}
