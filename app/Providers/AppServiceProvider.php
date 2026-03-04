<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Intentionally left blank.
        // App-level bindings should live here.
    }

    public function boot(): void
    {
        // Intentionally left blank.
    }
}
