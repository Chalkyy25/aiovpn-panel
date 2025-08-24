<?php

namespace App\Http\Controllers;

use App\Models\VpnServer;
use App\Traits\ExecutesRemoteCommands;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class VpnDisconnectController extends Controller
{
    use ExecutesRemoteCommands;

    public function __invoke(Request $request)
    {
        $request->validate([
            'server_id' => 'required|integer|exists:vpn_servers,id',
            'username'  => 'required|string',
        ]);

        /** @var \App\Models\VpnServer $server */
        $server   = VpnServer::findOrFail($request->integer('server_id'));
        $username = $request->string('username');

        // We’ll pipe a small script to `bash -s -- <username>`
        $remote = <<<'SH'
set -euo pipefail
NEEDLE="${1:?username required}"
MGMT_HOST="${MGMT_HOST:-127.0.0.1}"
MGMT_PORT="${MGMT_PORT:-7505}"

# Grab mgmt status v3 and find the first CID matching CN (col2) or Username (col10)
OUT="$({ printf 'status 3\r\n'; sleep 0.2; printf 'quit\r\n'; } \
  | nc -w 2 "$MGMT_HOST" "$MGMT_PORT" 2>/dev/null || true)"

CID="$(printf '%s\n' "$OUT" \
  | awk -F '\t' -v q="$NEEDLE" '$1=="CLIENT_LIST"{cn=$2; gsub(/\r$/,"",cn); user=$10; gsub(/\r$/,"",user); if(cn==q || user==q){print $11; exit}}')"

if [ -z "$CID" ]; then
  echo "ERR: no CID for user/CN: $NEEDLE"
  exit 2
fi

# Your daemon accepts CID-only — try that first, then fallbacks.
send() {
  # $1 command (no CRLF)
  { printf "%s\r\n" "$1"; sleep 0.2; printf "quit\r\n"; } \
    | nc -w 2 "$MGMT_HOST" "$MGMT_PORT" 2>/dev/null || true
}

RES="$(send "client-kill $CID")"
case "$RES" in *SUCCESS*|*success*) echo "OK: $NEEDLE (CID=$CID) killed"; exit 0;; esac

RES="$(send "kill $CID")"
case "$RES" in *SUCCESS*|*success*) echo "OK: $NEEDLE (CID=$CID) killed (legacy)"; exit 0;; esac

echo "ERR: mgmt did not return SUCCESS ($RES)"
exit 3
SH;

        // IMPORTANT: our trait should run raw commands (no extra quoting).
        // We run: bash -s -- '<username>' <<'SH' ... SH
        $cmd = "bash -s -- " . escapeshellarg($username) . " <<'SH'\n{$remote}\nSH\n";

        $res = $this->executeRemoteCommand($server, $cmd);

        $ok = ($res['status'] ?? 1) === 0;
        $msg = trim(implode("\n", $res['output'] ?? [])) ?: ($ok ? 'Disconnected' : 'Disconnect failed');

        Log::info('[disconnect] '.$server->name.' -> '.$msg);

        return response()->json([
            'ok'      => $ok,
            'message' => $msg,
        ], $ok ? 200 : 422);
    }
}