<?php

namespace App\Http\Controllers;

use App\Models\VpnServer;
use Illuminate\Http\Request;

class VpnDisconnectController extends Controller
{
    public function __invoke(Request $request)
    {
        $data = $request->validate([
            'username'  => 'required|string',
            'server_id' => 'required|integer|exists:vpn_servers,id',
        ]);

        $server = VpnServer::findOrFail($data['server_id']);

        if ($server->killClient($data['username'])) {
            return response()->json([
                'message' => "✅ User {$data['username']} disconnected from {$server->name}"
            ]);
        }

        return response()->json(['message' => '❌ Failed to disconnect user'], 500);
    }
}