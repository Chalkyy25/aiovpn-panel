<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\VpnServer;
use App\Models\VpnUser; // Make this model if you haven’t yet

class VpnUserController extends Controller
{
    public function index($serverId)
    {
        $server = VpnServer::findOrFail($serverId);
        $users = $server->vpnUsers; // Or whatever your relationship is
        return view('vpn_users.index', compact('server', 'users'));
    }

    public function create($serverId)
    {
        $server = VpnServer::findOrFail($serverId);
        return view('vpn_users.create', compact('server'));
    }

    public function store(Request $request, $serverId)
    {
        // Validate, create user, assign to server, etc
    }

    public function sync($serverId)
    {
        // Trigger your sync logic
    }
}
