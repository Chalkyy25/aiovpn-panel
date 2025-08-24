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
        'name'      => 'required|string|max:255',
        'ip_address'=> 'required|ip',
        'protocol'  => 'required|in:openvpn,wireguard',
    ]);

    $server = \App\Models\VpnServer::create([
        'name'        => $validated['name'],
        'ip_address'  => $validated['ip_address'],
        'protocol'    => $validated['protocol'],
        // optional sane defaults:
        'ssh_port'    => 22,
        'ssh_user'    => 'root',
        'ssh_type'    => 'key',
        'deployment_status' => 'queued',
        'status'      => 'pending',
    ]);

    \App\Jobs\DeployVpnServer::dispatch($server);

    return redirect()
        ->route('admin.servers.show', $server->id)
        ->with('success', 'Server created and deployment started.');
}

    public function show($id)
    {
        $vpnServer = VpnServer::findOrFail($id);
        return redirect()->route('admin.servers.show', $vpnServer->id);
    }

    public function destroy($id)
    {
        $vpnServer = VpnServer::findOrFail($id);
        $vpnServer->delete();

        return redirect()->route('admin.servers.index')
            ->with('success', 'Server deleted successfully.');
    }
}
