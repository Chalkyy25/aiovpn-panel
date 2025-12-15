<?php

namespace App\Http\Middleware;

use App\Models\Device;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class DeviceTokenAuth
{
    public function handle(Request $request, Closure $next)
    {
        $auth = (string) $request->header('Authorization', '');

        if (!str_starts_with($auth, 'Bearer ')) {
            return response()->json(['message' => 'Missing token'], 401);
        }

        $token = trim(substr($auth, 7));
        if ($token === '') {
            return response()->json(['message' => 'Missing token'], 401);
        }

        // Early-stage lookup (fine for now). Later we’ll optimize with token prefix indexing.
        $device = Device::whereNull('revoked_at')->get()->first(function ($d) use ($token) {
            return Hash::check($token, $d->token_hash);
        });

        if (!$device) {
            return response()->json(['message' => 'Invalid token'], 401);
        }

        // Update last seen + app version code if header provided
        $device->last_seen_at = now();
        $hdrVersion = $request->header('X-App-Version-Code');
        if (is_numeric($hdrVersion)) {
            $device->app_version_code = (int) $hdrVersion;
        }
        $device->save();

        $request->attributes->set('device', $device);

        return $next($request);
    }
}
