<?php

namespace App\Http\Responses;

use Laravel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;

class RegisterResponse implements RegisterResponseContract
{
    public function toResponse($request)
    {
        $user = $request->user();
        $host = $request->getHost();

        $role = $user->role;

        if (in_array($role, ['admin', 'reseller']) && $host === 'aiovpn.co.uk') {
            $target = $role === 'admin' ? 'admin/dashboard' : 'reseller/dashboard';
            return redirect()->away("https://panel.aiovpn.co.uk/{$target}");
        }

        $path = match ($role) {
            'admin' => '/admin/dashboard',
            'reseller' => '/reseller/dashboard',
            'client' => '/client/dashboard',
            default => '/dashboard',
        };

        return redirect()->intended($path);
    }
}
