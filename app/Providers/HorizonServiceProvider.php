<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    public function boot(): void
    {
        parent::boot();

        // Example: route Horizon notifications somewhere if you want
        // Horizon::routeSlackNotificationsTo(env('HORIZON_SLACK_WEBHOOK'), '#alerts');
        // Horizon::whenLongWaitDetected(function ($connection, $queue, $wait) {
        //     // notify / log
        // });
    }

    /**
     * Who can view Horizon in non-local environments.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', function ($user = null) {
            // 1) Always allow local/dev
            if (app()->environment('local')) {
                return true;
            }

            // 2) Optional IP allow-list (comma or space separated)
            $allowedIps = collect(preg_split('/[,\s]+/', (string) env('HORIZON_ALLOWED_IPS', ''), -1, PREG_SPLIT_NO_EMPTY));
            if ($allowedIps->isNotEmpty() && $allowedIps->contains(request()->ip())) {
                return true;
            }

            // 3) Must be logged in
            if (!$user) {
                return false;
            }

            // 4) Allow specific emails (comma or space separated)
            $allowedEmails = collect(preg_split('/[,\s]+/', (string) env('HORIZON_ALLOWED_EMAILS', ''), -1, PREG_SPLIT_NO_EMPTY))
                ->map(fn ($e) => Str::lower($e));

            if ($allowedEmails->isNotEmpty() && $allowedEmails->contains(Str::lower($user->email))) {
                return true;
            }

            // 5) Allow admins (adjust to your app’s notion of admin)
            // - If you have a role column:
            if (property_exists($user, 'role') && $user->role === 'admin') {
                return true;
            }
            // - Or a helper like $user->isAdmin():
            if (method_exists($user, 'isAdmin') && $user->isAdmin()) {
                return true;
            }
            // - Or a permission/ability:
            if ($user->can('access-admin')) {
                return true;
            }

            return false;
        });
    }
}