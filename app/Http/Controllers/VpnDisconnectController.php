<?php

namespace App\Http\Controllers;

use App\Models\VpnServer;
use App\Traits\ExecutesRemoteCommands;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class VpnDisconnectController extends Controller
{
    use ExecutesRemoteCommands; // ⬅️ add this

    public function __invoke(Request $request)
    {
        $data = $request->validate([
            'server_id' => 'required|integer|exists:vpn_servers,id',
            'username'  => 'required|string',
        ]);

        /** @var VpnServer $server */
        $server   = VpnServer::findOrFail($data['server_id']);
        $username = $data['username'];

        // awk to extract CID by CN (col 2) OR Username (col 10) from status v3
        $awk = '$1=="CLIENT_LIST"{cn=$2;sub(/\r$/,"",cn);user=$10;sub(/\r$/,"",user);if(cn==q||user==q){print $11;exit}}';

        // Build one safe remote bash -lc payload (no heredoc headaches)
        $script =
            'NEEDLE=' . escapeshellarg($username) . '; ' .
            'OUT=$({ printf "status 3\r\n"; sleep 0.3; printf "quit\r\n"; } | nc -w 2 127.0.0.1 7505 2>/dev/null); ' .
            'CID=$(printf "%s\n" "$OUT" | awk -F "\t" -v q="$NEEDLE" \'' . $awk . '\'); ' .
            '[ -n "$CID" ] || { echo "ERR: no CID for user/CN: $NEEDLE"; exit 2; }; ' .
            'RES=$({ printf "client-kill %s\r\n" "$CID"; sleep 0.2; printf "quit\r\n"; } | nc -w 2 127.0.0.1 7505 2>/dev/null); ' .
            'echo "$RES"; echo "$RES" | grep -qi SUCCESS';

        $remote = 'bash -lc ' . escapeshellarg($script);

        // ⬇️ use the trait method from the controller (NOT $server->...)
        $res = $this->executeRemoteCommand($server, $remote);

        if ((int)($res['status'] ?? 1) !== 0) {
            Log::error('Disconnect failed', ['server' => $server->id, 'out' => $res['output'] ?? []]);

            return response()->json([
                'ok'      => false,
                'message' => 'Disconnect failed',
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