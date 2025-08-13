<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * Model → Policy map.
     */
    protected $policies = [
        \App\Models\User::class => \App\Policies\UserPolicy::class,
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        // Registers the policies above
        $this->registerPolicies();

        // Optional: make admins superusers (policies/gates auto-allow)
        Gate::before(function ($user, string $ability) {
            return ($user->role === 'admin') ? true : null;
        });

        // Gate used in UI (e.g., show "Manage Credits" only to admins)
        Gate::define('manage-credits', function ($user) {
            return $user->role === 'admin';
        });
    }
}