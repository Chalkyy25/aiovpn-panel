<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function showLoginForm()
    {
        return response()
            ->view('client.login') // resources/views/client/login.blade.php
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $remember = (bool) $request->boolean('remember');

        if (! Auth::guard('client')->attempt($credentials, $remember)) {
            throw ValidationException::withMessages([
                'username' => 'Invalid username or password.',
            ]);
        }

        $request->session()->regenerate();

        return redirect()->intended(route('client.dashboard'));
    }

    public function logout(Request $request)
    {
        try {
            Auth::guard('client')->logout();
        } catch (\Throwable $e) {
            Log::warning('Client logout: guard logout failed', [
                'error' => $e->getMessage(),
            ]);
        }

        try {
            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }
        } catch (\Throwable $e) {
            Log::warning('Client logout: session invalidate failed', [
                'error' => $e->getMessage(),
            ]);
        }

        return redirect()->to('/login');
    }
}