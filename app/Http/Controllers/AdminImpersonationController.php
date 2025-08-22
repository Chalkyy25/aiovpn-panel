<?php

namespace App\Http\Controllers;

use App\Models\VpnUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;

class AdminImpersonationController extends Controller
{
    /**
     * Impersonate a VPN user (admin only)
     */
    public function impersonate(VpnUser $vpnUser)
    {
        // Ensure only admins can impersonate
        if (!Auth::user() || Auth::user()->role !== 'admin') {
            abort(403, 'Unauthorized');
        }

        // Store the original admin user ID in session
        Session::put('impersonating_admin_id', Auth::id());
        Session::put('impersonating_admin_name', Auth::user()->name);

        // Log in as the VPN user using the client guard
        Auth::guard('client')->login($vpnUser);

        return redirect()->route('client.dashboard')
            ->with('success', "You are now logged in as {$vpnUser->username}. Click 'Stop Impersonation' to return to admin panel.");
    }

    /**
     * Stop impersonating and return to admin account
     */
    public function stopImpersonation()
    {
        // Get the original admin user ID
        $adminId = Session::get('impersonating_admin_id');
        $adminName = Session::get('impersonating_admin_name');

        if (!$adminId) {
            return redirect()->route('admin.dashboard')
                ->with('error', 'No active impersonation session found.');
        }

        // Log out from client guard
        Auth::guard('client')->logout();

        // Clear impersonation session data
        Session::forget(['impersonating_admin_id', 'impersonating_admin_name']);

        // Redirect back to admin panel
        return redirect()->route('admin.vpn-users.index')
            ->with('success', "Stopped impersonating. Welcome back, {$adminName}!");
    }

    /**
     * Check if currently impersonating
     */
    public function isImpersonating()
    {
        return Session::has('impersonating_admin_id');
    }
}
