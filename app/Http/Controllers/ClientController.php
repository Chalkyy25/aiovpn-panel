<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\VpnServer;
use App\Jobs\SyncOpenVPNCredentials;
use App\Jobs\GenerateOvpnFile;
use App\Services\VpnConfigBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Exception;

class ClientController extends Controller
{
public function create()
{
    $client = null;

    if (session('client_id')) {
        $client = Client::find(session('client_id'));
    }

    $servers = VpnServer::all();

    return view('client.create', compact('servers', 'client'));
}
    public function store(Request $request)
    {
        $request->validate([
            'vpn_server_id' => 'required|exists:vpn_servers,id',
        ]);

        $username = 'user_' . Str::random(5);
        $password = Str::random(10);

        $client = Client::create([
            'username' => $username,
            'password' => $password,
            'vpn_server_id' => $request->vpn_server_id,
        ]);

        // Dispatch jobs to sync credentials and generate OVPN config
        SyncOpenVPNCredentials::dispatch($client);
        GenerateOvpnFile::dispatch($client);

return redirect()->route('clients.create')
    ->with('success', 'Client created and synced to VPN.')
    ->with('client_id', $client->id);
    }

public function download($id)
{
    try {
        $client = Client::findOrFail($id);

        // ✅ SECURITY FIX: Generate config on-demand instead of reading from disk
        // Note: This assumes Client model has a relationship to VpnServer
        $server = $client->vpnServer ?? VpnServer::first();

        if (!$server) {
            abort(404, 'No VPN server available for this client');
        }

        // Convert Client to VpnUser-like object for compatibility
        $vpnUser = (object) [
            'username' => $client->username,
            'password' => $client->password
        ];

        $configContent = VpnConfigBuilder::generateOpenVpnConfigString($vpnUser, $server);

        return response($configContent)
            ->header('Content-Type', 'application/x-openvpn-profile')
            ->header('Content-Disposition', "attachment; filename=\"{$client->username}.ovpn\"")
            ->header('Cache-Control', 'no-cache, no-store, must-revalidate')
            ->header('Pragma', 'no-cache')
            ->header('Expires', '0');

    } catch (Exception $e) {
        abort(500, 'Failed to generate config: ' . $e->getMessage());
    }
}

public function index()
{
    $clients = \App\Models\Client::with('vpnServer')->latest()->get();

    return view('client.index', compact('clients'));
}




}
