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
        ]);

        $user = VpnUser::where('username', $data['username'])->first();

        if (!$user) {
            return response()->json(['message' => 'Invalid username or password'], 401);
        }

        $ok = false;

        // 1) Prefer hashed password check
        if (!empty($user->password) && Hash::check($data['password'], $user->password)) {
            $ok = true;
        }

        // 2) Fallback to plain_password column if present
        if (!$ok && !empty($user->plain_password) && hash_equals($user->plain_password, $data['password'])) {
            $ok = true;
        }

        if (!$ok) {
            return response()->json(['message' => 'Invalid username or password'], 401);
        }

        if (!$user->is_active || $user->isExpired) {
            return response()->json(['message' => 'Account inactive or expired'], 403);
        }

        $token = $user->createToken('mobile')->plainTextToken;

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
}