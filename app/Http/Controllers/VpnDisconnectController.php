<?php

namespace App\Http\Controllers;

use App\Models\VpnConnection;
use App\Models\VpnServer;
use App\Models\VpnUser;
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
            'client_id'   => 'nullable|integer|min:0',
            'username'    => 'nullable|string',
            'protocol'    => 'nullable|string',
            'session_key' => 'nullable|string',
            'public_key'  => 'nullable|string',
        ]);

        if (
            !($data['client_id'] ?? null)
            && !($data['username'] ?? null)
            && !($data['session_key'] ?? null)
        ) {
            return response()->json([
                'message' => 'client_id, username, or session_key is required',
            ], 422);
        }

        $proto      = strtoupper(trim((string) ($data['protocol'] ?? '')));
        $sessionKey = $data['session_key'] ?? null;

        $isWireGuard =
            ($proto === 'WIREGUARD')
            || (is_string($sessionKey) && str_starts_with($sessionKey, 'wg:'));

        if ($isWireGuard) {
            return $this->disconnectWireGuard($server, $data);
        }

        return $this->disconnectOpenVpn($server, $data);
    }

    private function disconnectOpenVpn(VpnServer $server, array $data): JsonResponse
    {
        $mgmtPort = (int) ($server->mgmt_port ?? 7505);
        $cid      = $data['client_id'] ?? null;
        $cn       = $data['username'] ?? null;

        // Resolve CID from username if missing
        if (!$cid && $cn) {
            $cid = $this->resolveCidByCn($server, $mgmtPort, $cn);
        }

        $killed = false;
        $cmdOut = [];

        if ($cid !== null) {

            $cmd = sprintf(
                'echo -e "kill %d\nquit\n" | nc -w 5 127.0.0.1 %d',
                $cid,
                $mgmtPort
            );

            $res = $this->executeRemoteCommand(
                $server,
                'bash -lc ' . escapeshellarg($cmd)
            );

            $killed = (($res['status'] ?? 1) === 0);
            $cmdOut = $res;

            Log::channel('vpn')->{$killed ? 'info' : 'warning'}(
                ($killed ? '💀 Disconnected' : '⚠️ Failed to disconnect')
                . " openvpn client_id={$cid} on {$server->name}",
                $res
            );

        } else {

            Log::channel('vpn')->warning(
                "⚠️ No CID resolved for CN={$cn} on {$server->name}"
            );
        }

        try {
            $this->markDisconnectedInDbOpenVpn($server, $cid, $cn);
        } catch (\Throwable $e) {

            Log::channel('vpn')->warning(
                'DB disconnect mark failed',
                ['error' => $e->getMessage()]
            );
        }

        return response()->json([
            'ok'        => (bool) $killed,
            'message'   => ($killed ? 'Disconnected ' : 'Tried to disconnect ')
                . ($cn ?? ('#' . $cid)),
            'server_id' => $server->id,
            'client_id' => $cid,
            'username'  => $cn,
            'output'    => array_filter($cmdOut['output'] ?? []),
            'stderr'    => array_filter($cmdOut['stderr'] ?? []),
        ]);
    }

    private function disconnectWireGuard(VpnServer $server, array $data): JsonResponse
    {
        $pub = $data['public_key'] ?? null;
        $cn  = $data['username'] ?? null;

        if (!$pub) {
            return response()->json([
                'ok'      => false,
                'message' => 'WireGuard disconnect requires public_key',
            ], 422);
        }

        try {
            $this->markDisconnectedInDbWireGuard($server, $pub);
        } catch (\Throwable $e) {

            Log::channel('vpn')->warning(
                'WG DB disconnect mark failed',
                ['error' => $e->getMessage()]
            );
        }

        return response()->json([
            'ok'         => true,
            'message'    => 'WireGuard peer marked for disconnect',
            'server_id'  => $server->id,
            'public_key' => $pub,
            'username'   => $cn,
        ]);
    }

    protected function resolveCidByCn(
        VpnServer $server,
        int $mgmtPort,
        string $cn
    ): ?int {

        $script = <<<BASH
set -e
out=\$(echo -e "status 2\nquit\n" | nc -w 5 127.0.0.1 {$mgmtPort} || true)
echo "\$out" | awk -F',' -v cn="{$this->escAwk($cn)}" '
  \$1=="CLIENT_LIST" && \$2==cn {print \$NF; found=1}
  END { if (!found) exit 1 }
'
BASH;

        $res = $this->executeRemoteCommand(
            $server,
            'bash -lc ' . escapeshellarg($script)
        );

        if (($res['status'] ?? 1) === 0) {

            $val = trim(implode("\n", $res['output'] ?? []));

            if ($val !== '' && ctype_digit($val)) {
                return (int) $val;
            }
        }

        return null;
    }

    protected function markDisconnectedInDbOpenVpn(
        VpnServer $server,
        ?int $cid,
        ?string $cn
    ): void {

        $q = VpnConnection::query()
            ->where('vpn_server_id', $server->id)
            ->where('is_active', true)
            ->whereNull('disconnected_at');

        if ($cid !== null) {

            $q->where('session_key', (string) $cid);

        } elseif ($cn) {

            $uid = VpnUser::where('username', $cn)->value('id');

            if ($uid) {
                $q->where('vpn_user_id', $uid);
            }
        }

        $conn = $q->latest()->first();

        if ($conn) {

            $conn->is_active = false;
            $conn->disconnected_at = now();
            $conn->save();
        }
    }

    protected function markDisconnectedInDbWireGuard(
        VpnServer $server,
        string $publicKey
    ): void {

        $conn = VpnConnection::query()
            ->where('vpn_server_id', $server->id)
            ->where('is_active', true)
            ->whereNull('disconnected_at')
            ->where('wg_public_key', $publicKey)
            ->latest()
            ->first();

        if ($conn) {

            $conn->is_active = false;
            $conn->disconnected_at = now();
            $conn->save();
        }
    }

    private function escAwk(string $s): string
    {
        return str_replace(
            ['\\', '"'],
            ['\\\\', '\\"'],
            $s
        );
    }
}