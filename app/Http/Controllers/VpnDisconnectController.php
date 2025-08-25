<?php

// app/Http/Controllers/VpnDisconnectController.php
namespace App\Http\Controllers;

use App\Models\VpnServer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class VpnDisconnectController extends Controller
{
    public function __invoke(Request $request)
    {
        $data = $request->validate([
            'server_id' => 'required|integer|exists:vpn_servers,id',
            'username'  => 'required|string',
        ]);

        /** @var VpnServer $server */
        $server   = VpnServer::findOrFail($data['server_id']);
        $username = $data['username'];

        // Build a single safe bash -lc payload (no heredoc).
        // awk program to grab CID by CN (col 2) OR Username (col 10)
        $awk = '$1=="CLIENT_LIST"{cn=$2;sub(/\r$/,"",cn);user=$10;sub(/\r$/,"",user);if(cn==q||user==q){print $11;exit}}';

        $script =
            'NEEDLE=' . escapeshellarg($username) . '; ' .
            // Ask mgmt for status v3
            'OUT=$({ printf "status 3\r\n"; sleep 0.3; printf "quit\r\n"; } | nc -w 2 127.0.0.1 7505 2>/dev/null); ' .
            // Extract CID by CN or Username
            'CID=$(printf "%s\n" "$OUT" | awk -F "\t" -v q="$NEEDLE" \'' . $awk . '\'); ' .
            // Bail if none
            '[ -n "$CID" ] || { echo "ERR: no CID for user/CN: $NEEDLE"; exit 2; }; ' .
            // Kill by CID (works on your daemon)
            'RES=$({ printf "client-kill %s\r\n" "$CID"; sleep 0.2; printf "quit\r\n"; } | nc -w 2 127.0.0.1 7505 2>/dev/null); ' .
            'echo "$RES"; echo "$RES" | grep -qi SUCCESS';

        // Wrap for remote
        $remote = 'bash -lc ' . escapeshellarg($script);

        $res = $server->executeRemoteCommand($server, $remote);

        // Consider it ok if exit 0 and output contained SUCCESS
        $ok = ((int)($res['status'] ?? 1) === 0);
        if (!$ok) {
            Log::error('Disconnect failed', ['server' => $server->id, 'out' => $res['output'] ?? []]);
            return response()->json([
                'ok'      => false,
                'message' => "Disconnect failed",
                'output'  => $res['output'] ?? [],
            ], 500);
        }

        return response()->json([
            'ok'      => true,
            'message' => "Disconnected {$username} from {$server->name}",
            'output'  => $res['output'] ?? [],
        ]);
    }
}