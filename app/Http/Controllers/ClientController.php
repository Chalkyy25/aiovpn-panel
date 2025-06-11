<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\VpnServer;
use App\Jobs\SyncOpenVPNCredentials;
use App\Jobs\GenerateOvpnFile;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

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
    $client = Client::findOrFail($id);
    $path = "ovpn_configs/{$client->username}.ovpn";

    if (!Storage::exists($path)) {
        abort(404, 'Config not found');
    }

    return response()->download(storage_path("app/{$path}"), "{$client->username}.ovpn");
}

public function index()
{
    $clients = \App\Models\Client::with('vpnServer')->latest()->get();

    return view('client.index', compact('clients'));
}




}
