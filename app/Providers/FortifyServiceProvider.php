<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;
use App\Actions\Fortify\CreateNewUser;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class FortifyServiceProvider extends ServiceProvider
{
    public function register()
    {
        // 🔐 Custom Login Redirect
    $this->app->singleton(LoginResponseContract::class, function () {
    return new class implements LoginResponseContract {
        public function toResponse($request)
        {
            $user = $request->user();
            $host = $request->header('host');
            $host = Str::replaceFirst('www.', '', $host); // ✅ Strip 'www.'

            Log::info('[LOGIN] Redirection Logic Triggered', [
                'email' => $user->email,
                'role' => $user->role,
                'host' => $host,
            ]);

            if (in_array($user->role, ['admin', 'reseller']) && $host === 'aiovpn.co.uk') {
                $target = $user->role === 'admin' ? 'admin/dashboard' : 'reseller/dashboard';
                return redirect()->away("https://panel.aiovpn.co.uk/{$target}");
            }

            $path = match ($user->role) {
                'admin' => '/admin/dashboard',
                'reseller' => '/reseller/dashboard',
                'client' => '/client/dashboard',
                default => '/dashboard',
            };

            return redirect()->intended($path);
        }
    };
});
        // ✅ Optional: Custom Register Redirect
        $this->app->singleton(RegisterResponseContract::class, function () {
            return new class implements RegisterResponseContract {
                public function toResponse($request)
                {
                    $user = $request->user();
                    $path = match ($user->role) {
                        'admin' => '/admin/dashboard',
                        'reseller' => '/reseller/dashboard',
                        'client' => '/client/dashboard',
                        default => '/dashboard',
                    };
                    return redirect()->intended($path);
                }
            };
        });
    }

    public function boot()
    {
        Fortify::createUsersUsing(CreateNewUser::class);
    }
}
