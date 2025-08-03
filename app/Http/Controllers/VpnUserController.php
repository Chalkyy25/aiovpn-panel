<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\VpnServer;
use App\Models\VpnUser;
use App\Jobs\GenerateVpnConfig;

class VpnUserController extends Controller
{
    public function index($vpnServer) // Matches: admin/servers/{vpnServer}/users
    {
        $server = VpnServer::findOrFail($vpnServer);
        $users = $server->vpnUsers;

        return view('livewire.pages.admin.vpn-user-list', compact('server', 'users'));
    }

    public function create($vpnServer)
    {
        $server = VpnServer::findOrFail($vpnServer);

        return view('livewire.pages.admin.create-user', compact('server'));


    }

    public function store(Request $request, $vpnServer) // Matches: admin/servers/{vpnServer}/users
    {
        $server = VpnServer::findOrFail($vpnServer);

        $validated = $request->validate([
            'username' => 'required|string|max:255|unique:vpn_users',
            'password' => 'required|string|min:6',
        ]);

        // Create the new VPN user
        $user = VpnUser::create(array_merge($validated, ['server_id' => $server->id]));

        // Dispatch configuration generation
        GenerateVpnConfig::dispatch($user, $server->protocol);

        return redirect()->route('admin.servers.users.index', ['vpnServer' => $server->id])
            ->with('success', 'User created, and VPN configuration is being generated.');
    }

    public function sync($vpnServer) // Matches: admin/servers/{vpnServer}/users/sync
    {
        $server = VpnServer::findOrFail($vpnServer);

        foreach ($server->vpnUsers as $user) {
            GenerateVpnConfig::dispatch($user, $server->protocol);
        }

        return back()->with('success', 'All VPN user configurations have been synced.');
    }
}
