<?php

namespace App\Http\Controllers;

use App\Models\VpnServer;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class VpnDisconnectController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'server_id' => 'required|integer|exists:vpn_servers,id',
            'username'  => 'required|string|min:1',
        ]);

        $server   = VpnServer::findOrFail($data['server_id']);
        $username = $data['username'];

        try {
            $ssh = $server->getSshCommand();
        } catch (\Throwable $e) {
            return response()->json(['message' => 'SSH not configured'], 500);
        }

        $script = <<<'BASH'
set -euo pipefail

NEEDLE="${1:?username required}"
MGMT_HOST="127.0.0.1"
MGMT_PORT="7505"

# Grab mgmt status
OUT=$({ printf "status 3\r\n"; sleep 0.2; printf "quit\r\n"; } \
    | nc -w 2 "$MGMT_HOST" "$MGMT_PORT" 2>/dev/null)

CID=$(printf "%s\n" "$OUT" | awk -F "\t" -v q="$NEEDLE" '
  $1=="CLIENT_LIST" {
    cn=$2; user=$10; cid=$11;
    if (cn==q || user==q) { print cid; exit }
  }')

if [ -z "$CID" ]; then
  echo "ERR: no CID found for $NEEDLE"
  exit 2
fi

RES=$({ printf "client-kill %s\r\n" "$CID"; sleep 0.2; printf "quit\r\n"; } \
    | nc -w 2 "$MGMT_HOST" "$MGMT_PORT" 2>/dev/null)

echo "$RES"
echo "$RES" | grep -qi SUCCESS
BASH;

        $cmd = $ssh.' bash -s -- '.escapeshellarg($username)." <<'BASH'\n".$script."\nBASH";

        $proc = proc_open($cmd, [
            0=>["pipe","r"], 1=>["pipe","w"], 2=>["pipe","w"]
        ], $pipes);

        if (!is_resource($proc)) {
            return response()->json(['message'=>'SSH process failed'], 500);
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
        $status = proc_close($proc);

        if ($status === 0) {
            return response()->json([
                'success' => true,
                'message' => "Disconnected {$username} from {$server->name}",
                'output'  => explode("\n", trim($stdout)),
            ]);
        }

        Log::error("Disconnect failed", ['out'=>[$stdout,$stderr]]);
        return response()->json([
            'success' => false,
            'message' => "Failed to disconnect {$username}",
            'output'  => explode("\n", trim($stdout."\n".$stderr)),
        ], 500);
    }
}