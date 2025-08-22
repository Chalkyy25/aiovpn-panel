<?php

namespace App\Http\Responses;

use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use App\Providers\RouteServiceProvider;

class LoginResponse implements LoginResponseContract
{
    public function toResponse($request)
    {
        $user = Auth::user();
        $host = $request->getHost();

        Log::info('LoginResponse triggered', [
            'user' => $user->email,
            'role' => $user->role,
            'host' => $host
        ]);

        if (in_array($user->role, ['admin', 'reseller']) && $host === 'aiovpn.co.uk') {
            $target = $user->role === 'admin' ? 'admin/dashboard' : 'reseller/dashboard';

            Log::info('Redirecting to panel.aiovpn.co.uk', ['target' => $target]);

            return redirect()->away("https://panel.aiovpn.co.uk/{$target}");
        }

        $path = match ($user->role) {
            'admin'    => '/admin/dashboard',
            'reseller' => '/reseller/dashboard',
            'client'   => '/client/dashboard',
            default    => RouteServiceProvider::HOME,
        };

        return redirect()->intended($path);
    }
}
