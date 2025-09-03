<?php

namespace App\Http\Controllers;

use App\Models\VpnServer;
use Illuminate\Http\Request;
use App\Traits\ExecutesRemoteCommands;
use Illuminate\Support\Facades\Log;

class VpnDisconnectController extends Controller
{
    use ExecutesRemoteCommands;

    /**
     * Disconnect a client by mgmt client_id.
     */
    public function disconnect(Request $request, VpnServer $server)
    {
        $validated = $request->validate([
            'client_id' => 'required|integer|min:0',
        ]);

        $clientId = $validated['client_id'];
        $mgmtPort = (int)($server->mgmt_port ?? 7505);

        // Mgmt disconnect command
        $cmd = sprintf('echo -e "kill %d\nquit\n" | nc -w 3 127.0.0.1 %d', $clientId, $mgmtPort);

        $res = $this->executeRemoteCommand($server, 'bash -lc ' . escapeshellarg($cmd));
        $success = ($res['status'] ?? 1) === 0;

        if ($success) {
            Log::channel('vpn')->info("💀 Disconnected client_id={$clientId} on {$server->name}");
        } else {
            Log::channel('vpn')->warning("⚠️ Failed to disconnect client_id={$clientId} on {$server->name}", $res);
        }

        return response()->json([
            'ok'        => $success,
            'server_id' => $server->id,
            'client_id' => $clientId,
            'status'    => $success ? 'disconnected' : 'failed',
            'output'    => $res['output'] ?? [],
            'stderr'    => $res['stderr'] ?? [],
        ]);
    }
}