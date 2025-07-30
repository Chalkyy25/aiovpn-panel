<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\VpnServer;
use App\Jobs\DeployVpnServer;

class VpnServerController extends Controller
{
    public function create()
    {
        return view('vpn_servers.create');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'ip' => 'required|ip',
            'protocol' => 'required|in:openvpn,wireguard',
        ]);

        $server = VpnServer::create($validated);

        DeployVpnServer::dispatch($server);

        return redirect()->route('vpn_servers.show', $server->id)
            ->with('success', 'Server created and deployment started.');
    }

    public function show($id)
    {
        $vpnServer = VpnServer::findOrFail($id);
        return view('vpn_servers.show', compact('vpnServer'));
    }

    public function destroy($id)
    {
        $vpnServer = VpnServer::findOrFail($id);
        $vpnServer->delete();

        return redirect()->route('admin.servers.index')
            ->with('success', 'Server deleted successfully.');
    }
}
