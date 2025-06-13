<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\VpnServer;
use Illuminate\Support\Facades\Auth;

class VpnConfigController extends Controller
{
    public function download(VpnServer $server)
    {
        $user = Auth::user();

        // Check if the user is assigned to this VPN server
        if (!$user->vpnServers()->where('vpn_server_id', $server->id)->exists()) {
            abort(403, 'Unauthorized to download this VPN config.');
        }

        // Adjust this path to where your .ovpn files are stored per server
        $configPath = storage_path("vpn_configs/{$server->id}/client.ovpn");

        if (!file_exists($configPath)) {
            abort(404, 'VPN config file not found.');
        }

        return response()->download($configPath, "{$server->name}.ovpn", [
            'Content-Type' => 'application/octet-stream',
        ]);
    }
}
