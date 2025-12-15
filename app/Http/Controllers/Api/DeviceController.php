<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Device;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DeviceController extends Controller
{
    public function register(Request $request)
    {
        $data = $request->validate([
            'device_uuid' => 'required|uuid',
            'model' => 'nullable|string|max:255',
            'os_version' => 'nullable|string|max:50',
            'app_version_code' => 'nullable|integer|min:0',
        ]);

        $token = Str::random(64);

        Device::updateOrCreate(
            ['device_uuid' => $data['device_uuid']],
            [
                'token_hash' => Hash::make($token),
                'model' => $data['model'] ?? null,
                'os_version' => $data['os_version'] ?? null,
                'app_version_code' => (int)($data['app_version_code'] ?? 0),
                'revoked_at' => null,
                'last_seen_at' => now(),
            ]
        );

        return response()->json(['device_token' => $token]);
    }
}
