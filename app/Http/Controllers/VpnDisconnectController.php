<?php

// app/Http/Controllers/VpnDisconnectController.php

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

        $res = $server->killClientDetailed($data['username']);

        if ($res['ok']) {
            return response()->json([
                'message' => "✅ Disconnected {$data['username']} from {$server->name}",
            ]);
        }

        return response()->json([
            'message' => '❌ Disconnect failed',
            'status'  => $res['status'],
            'output'  => $res['output'],
        ], 500);
    }
}