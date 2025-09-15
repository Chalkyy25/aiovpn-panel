<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\VpnUser;

class MobileProfileController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user(); // the authenticated vpn_user

        return response()->json([
            'id'       => $user->id,
            'username' => $user->username,
            'expires'  => $user->expires_at,
            'max_conn' => $user->max_connections,
            'servers'  => $user->vpnServers()->get(['id','name','ip_address','location']),
        ]);
    }

    public function show(Request $request, VpnUser $user)
    {
        if ($request->user()->id !== $user->id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $server = $user->vpnServers()->firstOrFail();

        $config = view('vpn.ovpn-template', [
            'server'   => $server,
            'username' => $user->username,
        ])->render();

        return response($config, 200, [
            'Content-Type'        => 'application/x-openvpn-profile',
            'Content-Disposition' => "attachment; filename=aio-{$user->username}.ovpn",
        ]);
    }
}
