<?php

namespace App\Http\Controllers;

use App\Models\VpnServer;
use App\Traits\ExecutesRemoteCommands;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\JsonResponse;

class VpnDisconnectController extends Controller
{
    use ExecutesRemoteCommands;

    /**
     * POST /admin/vpn/disconnect
     * JSON: { "server_id": 123, "username": "alice" }
     */
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'server_id' => ['required', 'integer', 'exists:vpn_servers,id'],
            'username'  => ['required', 'string'],
        ]);

        /** @var VpnServer $server */
        $server   = VpnServer::findOrFail($data['server_id']);
        $username = $data['username'];

        // Entire remote script lives in a single-quoted heredoc so NO escaping drama.
        // We pass only the username as $1 via "bash -s -- 'username'".
        $remote = <<<'BASH'
set -euo pipefail

NEEDLE="${1:?username required}"
MGMT_HOST="${MGMT_HOST:-127.0.0.1}"
MGMT_PORT="${MGMT_PORT:-7505}"

# Grab TSV status from mgmt interface (status-version 3)
OUT="$({ printf 'status 3\r\n'; sleep 0.3; printf 'quit\r\n'; } \
  | nc -w 2 "$MGMT_HOST" "$MGMT_PORT" 2>/dev/null || true)"

# Find first match by CN (col 2) OR Username (col 10). Output: CN<TAB>CID
PAIR=$(printf "%s\n" "$OUT" \
  | awk -F '\t' -v q="$NEEDLE" '
      $1=="CLIENT_LIST" {
        cn=$2;  sub(/\r$/,"",cn);
        user=$10; sub(/\r$/,"",user);
        if (cn==q || user==q) { print cn "\t" $11; exit }
      }')

CN=$(printf "%s" "$PAIR" | cut -f1)
CID=$(printf "%s" "$PAIR" | cut -f2)

if [ -z "${CID:-}" ]; then
  echo "ERR: no CID for user/CN: $NEEDLE"
  # Helpful preview of rows (CN|USER|CID)
  printf "%s\n" "$OUT" | awk -F"\t" '$1=="CLIENT_LIST"{print $2 "|" $10 "|" $11}' | head -n 5
  exit 2
fi

# Your daemon accepts CID-only kills; do that first.
send_cmd() {
  # $1 = command string (w/out CRLF), $2 = label
  RES=$({ printf '%s\r\n' "$1"; sleep 0.3; printf 'quit\r\n'; } \
        | nc -w 2 "$MGMT_HOST" "$MGMT_PORT" 2>/dev/null || true)
  echo "$2: $RES"
  case "$RES" in *SUCCESS*|*success*) return 0 ;; esac
  return 1
}

# Try CID-only first; then fallbacks if needed.
send_cmd "client-kill $CID"     "REPLY1" || \
send_cmd "client-kill $CN $CID" "REPLY2" || \
send_cmd "kill $CID"            "REPLY3" || {
  echo "ERR: mgmt did not return SUCCESS"
  exit 3
}
BASH;

        // IMPORTANT: use bash -s -- 'username' <<'BASH' … BASH
        $command = "bash -s -- " . escapeshellarg($username) . " <<'BASH'\n{$remote}\nBASH";

        $res = $this->executeRemoteCommand($server, $command);

        if (($res['status'] ?? 1) === 0) {
            return response()->json([
                'ok'      => true,
                'message' => "Disconnect requested for {$username} on {$server->name}.",
                'output'  => $res['output'] ?? [],
            ]);
        }

        Log::error('Disconnect failed', ['server' => $server->id, 'out' => $res['output'] ?? []]);

        return response()->json([
            'ok'      => false,
            'message' => 'Error disconnecting user.',
            'output'  => $res['output'] ?? [],
        ], 422);
    }
}