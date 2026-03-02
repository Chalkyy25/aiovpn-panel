<?php

namespace App\Http\Controllers\Admin\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LoginController extends Controller
{
    public function show()
    {
        return view('auth.login'); // the admin login view
    }

    public function login(Request $request)
    {
        $creds = $request->validate([
            'email'    => ['required','email'],
            'password' => ['required'],
        ]);

        if (Auth::guard('web')->attempt($creds, $request->boolean('remember'))) {
            $request->session()->regenerate();
$user = Auth::guard('web')->user();

if (! in_array($user->role, ['admin', 'reseller'], true)) {
    Auth::guard('web')->logout();

    return back()
        ->withErrors(['email' => 'This login is for admins/resellers only.'])
        ->onlyInput('email');
}

return redirect($user->role === 'admin' ? '/panel' : '/reseller-panel');

        }

        return back()
            ->withErrors(['email' => 'Invalid credentials.'])
            ->onlyInput('email');
    }

    public function logout(Request $request)
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect()->route('login.form');
    }
}
