<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    protected $policies = [
        // Model::class => Policy::class,
    ];

    public function boot(): void
    {
        // Only admins can manage credits
        Gate::define('manage-credits', function ($user) {
            // adjust if your role field/name differs
            return $user && $user->role === 'admin';
        });
    }
}