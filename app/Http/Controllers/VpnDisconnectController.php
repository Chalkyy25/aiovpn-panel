<?php

namespace App\Http\Controllers;

use App\Models\VpnServer;
use App\Models\VpnUser;
use App\Models\VpnUserConnection;
use App\Traits\ExecutesRemoteCommands;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class VpnDisconnectController extends Controller
{
    use ExecutesRemoteCommands;

    public function disconnect(Request $request, VpnServer $server): JsonResponse
    {
        $data = $request->validate([
            'client_id' => 'nullable|integer|min:0',
            'username'  => 'nullable|string',
        ]);

        if (!($data['client_id'] ?? null) && !($data['username'] ?? null)) {
            return response()->json(['message' => 'client_id or username is required'], 422);
        }

        $mgmtPort = (int)($server->mgmt_port ?? 7505);
        $cid      = $data['client_id'] ?? null;
        $cn       = $data['username']  ?? null;

        // If CID not provided, resolve it by CN from "status 2"
        if (!$cid && $cn) {
            $cid = $this->resolveCidByCn($server, $mgmtPort, $cn);
        }

        $killed = false;
        $cmdOut = [];

        if ($cid !== null) {
            $cmd = sprintf('echo -e "kill %d\nquit\n" | nc -w 5 127.0.0.1 %d', $cid, $mgmtPort);
            $res = $this->executeRemoteCommand($server, 'bash -lc ' . escapeshellarg($cmd));

            $killed = (($res['status'] ?? 1) === 0);
            $cmdOut = $res;

            Log::channel('vpn')->{$killed ? 'info' : 'warning'}(
                ($killed ? '💀 Disconnected' : '⚠️ Failed to disconnect') . " client_id={$cid} on {$server->name}",
                $res
            );
        } else {
            Log::channel('vpn')->warning("⚠️ No CID resolved for CN={$cn} on {$server->name}");
        }

        // Best-effort DB mark
        try {
            $this->markDisconnectedInDb($server, $cid, $cn);
        } catch (\Throwable $e) {
            Log::channel('vpn')->warning('DB disconnect mark failed', ['error' => $e->getMessage()]);
        }

        // NOTE: No broadcasting here. One broadcaster owns mgmt.update (poller / wg-events).
        return response()->json([
            'ok'        => (bool) $killed,
            'message'   => ($killed ? 'Disconnected ' : 'Tried to disconnect ') . ($cn ?? ('#' . $cid)),
            'server_id' => $server->id,
            'client_id' => $cid,
            'username'  => $cn,
            'output'    => array_filter($cmdOut['output'] ?? []),
            'stderr'    => array_filter($cmdOut['stderr'] ?? []),
        ]);
    }

    protected function resolveCidByCn(VpnServer $server, int $mgmtPort, string $cn): ?int
    {
        $script = <<<BASH
set -e
out=$(echo -e "status 2\nquit\n" | nc -w 5 127.0.0.1 {$mgmtPort} || true)
echo "$out" | awk -F',' -v cn="{$this->escAwk($cn)}" '
  $1=="CLIENT_LIST" && $2==cn {print $NF; found=1}
  END { if (!found) exit 1 }
'
BASH;

        $res = $this->executeRemoteCommand($server, 'bash -lc ' . escapeshellarg($script));
        if (($res['status'] ?? 1) === 0) {
            $val = trim(implode("\n", $res['output'] ?? []));
            if ($val !== '' && ctype_digit($val)) return (int) $val;
        }

        return null;
    }

    protected function markDisconnectedInDb(VpnServer $server, ?int $cid, ?string $cn): void
    {
        $q = VpnUserConnection::query()
            ->where('vpn_server_id', $server->id)
            ->where('is_connected', true);

        if ($cid !== null) {
            $q->where('id', $cid);
        } elseif ($cn) {
            $uid = VpnUser::where('username', $cn)->value('id');
            if ($uid) $q->where('vpn_user_id', $uid);
        }

        $conn = $q->first();

        if ($conn) {
            $conn->is_connected     = false;
            $conn->disconnected_at  = now();
            $conn->session_duration = $conn->connected_at ? now()->diffInSeconds($conn->connected_at) : null;
            $conn->save();

            VpnUserConnection::updateUserOnlineStatusIfNoActiveConnections($conn->vpn_user_id);
        }
    }

    private function escAwk(string $s): string
    {
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $s);
    }
}