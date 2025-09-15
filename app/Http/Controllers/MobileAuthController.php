<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\VpnUser;
use Illuminate\Support\Facades\Hash;

class MobileAuthController extends Controller
{
    public function login(Request $request)
    {
        $data = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $user = VpnUser::where('username', $data['username'])->first();

        if (!$user) {
            return response()->json(['message' => 'Invalid username or password'], 401);
        }

        // If your vpn_users.password is already hashed:
        // if (!Hash::check($data['password'], $user->password)) { … }

        // If your vpn_users.password is plaintext (like psw-file):
        if ($user->password !== $data['password']) {
            return response()->json(['message' => 'Invalid username or password'], 401);
        }

        $token = $user->createToken('mobile')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user'  => [
                'id'       => $user->id,
                'username' => $user->username,
                'active'   => $user->active ?? true,
                'expires'  => $user->expires_at ?? null,
            ],
        ]);
    }
}