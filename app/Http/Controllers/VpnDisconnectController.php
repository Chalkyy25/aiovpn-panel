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

        /** @var \App\Models\VpnServer $server */
        $server   = VpnServer::findOrFail($data['server_id']);
        $username = $data['username'];

        // Build SSH base command from your model
        try {
            $ssh = $server->getSshCommand();
        } catch (\Throwable $e) {
            Log::error("❌ getSshCommand failed: ".$e->getMessage());
            return response()->json(['message' => 'SSH not configured for this server'], 500);
        }

        // Heredoc script runs **on the VPN host**; no brittle escaping in PHP.
        $remoteScript = <<<'BASH'
set -euo pipefail

NEEDLE="${1:?username required}"
MGMT_HOST="${MGMT_HOST:-127.0.0.1}"
MGMT_PORT="${MGMT_PORT:-7505}"

# Grab management status (retry once)
get_status() {
  { printf "status 3\r\n"; sleep 0.3; printf "quit\r\n"; } \
    | nc -w 2 "$MGMT_HOST" "$MGMT_PORT" 2>/dev/null || true
}

OUT="$(get_status)"
if [ -z "$OUT" ]; then
  sleep 0.5
  OUT="$(get_status)"
fi

# 1) Primary parse: TSV (status-version 3)
CID="$(printf "%s\n" "$OUT" | awk -F "\t" -v q="$NEEDLE" '
  $1=="CLIENT_LIST" {
    cn=$2; gsub(/\r$/,"",cn);
    user=$10; gsub(/\r$/,"",user);
    cid=$11; gsub(/\r$/,"",cid);
    if (cn==q || user==q) { print cid; exit }
  }
')"

# 2) Fallback parser: any whitespace (very defensive)
if [ -z "$CID" ]; then
  CID="$(printf "%s\n" "$OUT" | awk -v q="$NEEDLE" '
    $1=="CLIENT_LIST" {
      # columns may be space-separated; try to infer:
      # ... CN(2) ... Username(N-3) ClientID(N-2)
      cn=$2
      n=NF
      if (n>=11) {
        user=$(n-3)
        cid=$(n-2)
        sub(/\r$/,"",cn); sub(/\r$/,"",user); sub(/\r$/,"",cid)
        if (cn==q || user==q) { print cid; exit }
      }
    }
  ')"
fi

if [ -z "$CID" ]; then
  echo "ERR: no CID for user/CN: $NEEDLE"
  # Helpful context (first few client rows)
  printf "%s\n" "$OUT" | awk -F"\t" '$1=="CLIENT_LIST"{ printf("CN=%s USER=%s CID=%s\n",$2,$10,$11) }' | head -n 6
  exit 2
fi

# Your OpenVPN accepts CID-only for client-kill (verified)
send_cmd() {
  # $1 = command label (for echo)
  RES=$({ printf "client-kill %s\r\n" "$CID"; sleep 0.2; printf "quit\r\n"; } \
    | nc -w 2 "$MGMT_HOST" "$MGMT_PORT" 2>/dev/null || true)
  echo "$1: $RES"
  echo "$RES" | grep -qi SUCCESS
}

if send_cmd "REPLY"; then
  exit 0
fi

echo "ERR: mgmt did not return SUCCESS"
exit 3
BASH;

        // Build a single SSH command that feeds the script via heredoc.
        // We pass the username as the single arg to the remote script (`$1`).
        $cmd = $ssh . ' bash -s -- ' . escapeshellarg($username) . " <<'BASH'\n"
             . $remoteScript
             . "\nBASH";

        // Run and capture
        $descriptors = [
            0 => ["pipe", "r"],
            1 => ["pipe", "w"],
            2 => ["pipe", "w"],
        ];
        $proc = proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($proc)) {
            return response()->json(['message' => 'Failed to start SSH process'], 500);
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
        $status = proc_close($proc);

        if ($status === 0) {
            return response()->json([
                'message' => "Disconnected {$username}",
                'stdout'  => $stdout,
            ]);
        }

        Log::error('Disconnect failed', [
            'server' => $server->id,
            'out'    => array_filter([$stdout, $stderr]),
        ]);

        return response()->json([
            'message' => 'Disconnect failed',
            'stdout'  => $stdout,
            'stderr'  => $stderr,
        ], 500);
    }
}