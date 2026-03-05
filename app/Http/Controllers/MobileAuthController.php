<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Hash;
use App\Models\VpnUser;

class MobileAuthController extends Controller
{
    public function login(Request $request)
    {
        $data = $request->validate([
            'username' => ['required','string'],
            'password' => ['required','string'],
            'device_name' => ['nullable','string','max:255'],
        ]);

        $user = VpnUser::where('username', $data['username'])->first();

        if (!$user) {
            return response()->json(['message' => 'Invalid username or password'], 401);
        }

        $ok = false;
        $usedPlain = false;

        // 1) Prefer hashed password check
        if (!empty($user->password) && Hash::check($data['password'], $user->password)) {
            $ok = true;
        }

        // 2) Fallback to plain_password column if present
        if (!$ok && !empty($user->plain_password) && hash_equals($user->plain_password, $data['password'])) {
            $ok = true;
            $usedPlain = true;
        }

        if (!$ok) {
            return response()->json(['message' => 'Invalid username or password'], 401);
        }

	if (!$user->is_active || $user->isExpired()) {
            return response()->json(['message' => 'Account inactive or expired'], 403);
        }

        // If the user authenticated via legacy plain_password, upgrade them to a hashed password.
        // This preserves backwards compatibility while steadily removing plaintext storage.
        if ($usedPlain) {
            try {
                $user->forceFill([
                    'password' => Hash::make($data['password']),
                    'plain_password' => null,
                ])->save();
            } catch (\Throwable $e) {
                Log::warning('Failed to upgrade plain_password to hashed password', [
                    'vpn_user_id' => $user->id,
                    'err' => $e->getMessage(),
                ]);
            }
        }

        if (!empty($data['device_name'])) {
            $user->device_name = $data['device_name'];
            $user->save();
        }

        $tokenName = !empty($data['device_name'])
            ? ('mobile:' . $data['device_name'])
            : 'mobile';

        $token = $user->createToken($tokenName, ['mobile'])->plainTextToken;

        return response()->json([
            'token' => $token,
            'user'  => [
                'id'        => $user->id,
                'username'  => $user->username,
                'active'    => (bool) $user->is_active,
                'expires'   => optional($user->expires_at)->toISOString(),
                'max_conn'  => (int) $user->max_connections,
            ],
        ]);
    }

    public function logout(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['ok' => true]);
        }

        $token = $user->currentAccessToken();
        if ($token) {
            $token->delete();
        }

        return response()->json(['ok' => true]);
    }
}
