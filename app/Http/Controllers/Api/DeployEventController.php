<?php

namespace App\Http\Controllers\Api;

use App\Events\ServerMgmtEvent;
use App\Http\Controllers\Controller;
use App\Models\VpnServer;
use App\Models\VpnUser;
use App\Models\VpnUserConnection;
use App\Models\WireguardPeer;
use App\Traits\ExecutesRemoteCommands;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DeployEventController extends Controller
{
    use ExecutesRemoteCommands;

    private const OFFLINE_GRACE = 300; // seconds

    public function store(Request $request, VpnServer $server): JsonResponse
    {
        $data = $request->validate([
            'status'  => 'required|string',
            'message' => 'nullable|string',
            'ts'      => 'nullable|string',
            'users'   => 'nullable|array',
            'cn_list' => 'nullable|string',
            'clients' => 'nullable|integer',
        ]);

        if (strtolower($data['status']) !== 'mgmt') {
            return response()->json(['ok' => true]);
        }

        $ts  = $data['ts'] ?? now()->toIso8601String();
        $raw = $data['message'] ?? 'mgmt';
        $now = now();

        $incoming = $this->normalizeIncoming($data);

        Log::channel('vpn')->debug(sprintf(
            'MGMT EVENT server=%d ts=%s incoming=%d [%s]',
            $server->id,
            $ts,
            count($incoming),
            implode(',', array_column($incoming, 'username'))
        ));

        DB::transaction(function () use ($server, $incoming, $now) {

            [$idByOvpnName, $uidByWgPub] = $this->buildUserMaps($incoming);

            $touchedKeys = [];

            foreach ($incoming as $c) {
                $proto     = $this->proto($c['proto'] ?? null);
                $username  = trim((string)($c['username'] ?? ''));
                $publicKey = $c['public_key'] ?? null;
                $clientId  = isset($c['client_id']) ? (int) $c['client_id'] : null;
                $mgmtPort  = isset($c['mgmt_port']) ? (int) $c['mgmt_port'] : null;

                // ---- Resolve user id ----
                $uid = null;

                if ($proto === 'WIREGUARD') {
                    $key = $publicKey ?: $username;     // username may be pubkey
                    $publicKey = $key ?: null;          // force publicKey for WG

                    if ($publicKey && isset($uidByWgPub[$publicKey])) {
                        $uid = (int) $uidByWgPub[$publicKey];
                    }
                } else {
                    if ($username && isset($idByOvpnName[$username])) {
                        $uid = (int) $idByOvpnName[$username];
                    }
                }

                if (!$uid) {
                    Log::channel('vpn')->notice("MGMT: unknown {$proto} user='{$username}' server={$server->id}");
                    continue;
                }

                // ---- Build session key ----
                $sessionKey = $this->sessionKey($proto, $username, $clientId, $mgmtPort, $publicKey);
                if (!$sessionKey) {
                    Log::channel('vpn')->notice("MGMT: missing session identity proto={$proto} user='{$username}' server={$server->id}");
                    continue;
                }

                $touchedKeys[] = $sessionKey;

                $connectedAt = !empty($c['connected_at'])
                    ? $this->parseTime($c['connected_at'])
                    : null;

                // ✅ Upsert by server + session_key ONLY
                $row = VpnUserConnection::updateOrCreate(
                    [
                        'vpn_server_id' => $server->id,
                        'session_key'   => $sessionKey,
                    ],
                    [
                        'vpn_user_id'      => $uid,
                        'protocol'         => $proto,
                        'public_key'       => $proto === 'WIREGUARD' ? $publicKey : null,
                        'client_id'        => $proto === 'OPENVPN' ? $clientId : null,
                        'mgmt_port'        => $proto === 'OPENVPN' ? ($mgmtPort ?: 7505) : null,
                        'is_connected'     => true,
                        'disconnected_at'  => null,
                        'connected_at'     => $connectedAt ?? $now,
                        'client_ip'        => $c['client_ip'] ?? null,
                        'virtual_ip'       => $c['virtual_ip'] ?? null,
                        'bytes_received'   => (int) ($c['bytes_in'] ?? 0),
                        'bytes_sent'       => (int) ($c['bytes_out'] ?? 0),
                    ]
                );

                // user summary
                VpnUser::whereKey($uid)->update([
                    'is_online' => true,
                    'last_ip'   => $row->client_ip,
                ]);
            }

            // Disconnect sessions not present (after grace)
            $this->disconnectMissing($server->id, $touchedKeys, $now);

            // Server aggregates
            $liveKnown = VpnUserConnection::where('vpn_server_id', $server->id)
                ->where('is_connected', true)
                ->count();

            $server->forceFill([
                'online_users' => $liveKnown,
                'last_mgmt_at' => $now,
            ])->saveQuietly();

            // ✅ Enforce device limits right here AFTER state is accurate
            $this->enforceDeviceLimits($server->id, $now);
        });

        $enriched = $this->enrich($server);

        event(new ServerMgmtEvent(
            $server->id,
            $ts,
            $enriched,
            implode(',', array_column($enriched, 'username')),
            $raw
        ));

        return response()->json([
            'ok'        => true,
            'server_id' => $server->id,
            'clients'   => count($enriched),
            'users'     => $enriched,
        ]);
    }

    private function normalizeIncoming(array $data): array
    {
        $out = [];

        if (!empty($data['users']) && is_array($data['users'])) {
            foreach ($data['users'] as $u) {
                $u = is_string($u) ? ['username' => $u] : (array) $u;

                $proto = strtolower((string)($u['proto'] ?? $u['protocol'] ?? 'openvpn'));
                $proto = str_starts_with($proto, 'wire') ? 'wireguard' : 'openvpn';

                $username = (string)($u['username'] ?? $u['cn'] ?? $u['CommonName'] ?? 'unknown');
                $pub      = $u['public_key'] ?? $u['pubkey'] ?? null;

                $out[] = [
                    'proto'        => $proto,
                    'username'     => $username,
                    'public_key'   => $pub,
                    'client_id'    => isset($u['client_id']) ? (int)$u['client_id'] : null,
                    'mgmt_port'    => isset($u['mgmt_port']) ? (int)$u['mgmt_port'] : null,
                    'client_ip'    => $this->stripPort($u['client_ip'] ?? $u['RealAddress'] ?? null),
                    'virtual_ip'   => $this->stripCidr($u['virtual_ip'] ?? $u['VirtualAddress'] ?? null),
                    'connected_at' => $u['connected_at'] ?? $u['ConnectedSince'] ?? null,
                    'bytes_in'     => (int)($u['bytes_in'] ?? $u['BytesReceived'] ?? 0),
                    'bytes_out'    => (int)($u['bytes_out'] ?? $u['BytesSent'] ?? 0),
                ];
            }
        }

        // Filter garbage + dedupe by identity
        $seen = [];
        return array_values(array_filter($out, function ($r) use (&$seen) {
            $proto = strtolower((string)($r['proto'] ?? 'openvpn'));
            $name  = trim((string)($r['username'] ?? ''));

            if ($name === '' || strcasecmp($name, 'unknown') === 0 || strcasecmp($name, 'UNDEF') === 0) {
                return false;
            }

            if ($proto === 'wireguard') {
                $key = $r['public_key'] ?: $name;
                if (!preg_match('#^[A-Za-z0-9+/=]{32,80}$#', (string)$key)) return false;
                $dedupeKey = "wg|{$key}";
            } else {
                if (!preg_match('/^[A-Za-z0-9._-]{3,64}$/', $name)) return false;
                $dedupeKey = "ovpn|{$name}|" . ($r['client_id'] ?? 'nocid');
            }

            if (isset($seen[$dedupeKey])) return false;
            $seen[$dedupeKey] = true;
            return true;
        }));
    }

    private function buildUserMaps(array $incoming): array
    {
        $ovpnNames = [];
        $wgKeys    = [];

        foreach ($incoming as $c) {
            $proto = $this->proto($c['proto'] ?? null);
            if ($proto === 'WIREGUARD') {
                $wgKeys[] = $c['public_key'] ?: $c['username'];
            } else {
                $ovpnNames[] = $c['username'];
            }
        }

        $ovpnNames = array_values(array_unique(array_filter($ovpnNames)));
        $wgKeys    = array_values(array_unique(array_filter($wgKeys)));

        $idByOvpnName = $ovpnNames
            ? VpnUser::whereIn('username', $ovpnNames)->pluck('id', 'username')->all()
            : [];

        $uidByWgPub = $wgKeys
            ? WireguardPeer::whereIn('public_key', $wgKeys)->pluck('vpn_user_id', 'public_key')->all()
            : [];

        return [$idByOvpnName, $uidByWgPub];
    }

    private function sessionKey(string $proto, string $username, ?int $clientId, ?int $mgmtPort, ?string $publicKey): ?string
    {
        if ($proto === 'WIREGUARD') {
            $key = $publicKey ?: $username;
            return $key ? "wg:{$key}" : null;
        }

        if ($clientId === null) return null;
        $mp = $mgmtPort ?: 7505;
        return "ovpn:{$mp}:{$clientId}:{$username}";
    }

    private function disconnectMissing(int $serverId, array $touchedSessionKeys, Carbon $now): void
    {
        $graceAgo = $now->copy()->subSeconds(self::OFFLINE_GRACE);

        $q = VpnUserConnection::where('vpn_server_id', $serverId)
            ->where('is_connected', true);

        if (!empty($touchedSessionKeys)) {
            $q->whereNotIn('session_key', $touchedSessionKeys);
        }

        foreach ($q->get() as $row) {
            if ($row->updated_at && $row->updated_at->gt($graceAgo)) continue;

            $row->update([
                'is_connected'     => false,
                'disconnected_at'  => $now,
                'session_duration' => $row->connected_at ? $now->diffInSeconds($row->connected_at) : null,
            ]);

            VpnUserConnection::updateUserOnlineStatusIfNoActiveConnections($row->vpn_user_id);
        }
    }

    private function proto(?string $v): string
    {
        $p = strtolower((string)$v);
        if ($p === '' || str_starts_with($p, 'open') || str_starts_with($p, 'ovpn')) return 'OPENVPN';
        if (str_starts_with($p, 'wire')) return 'WIREGUARD';
        return strtoupper($p);
    }

    private function parseTime($value): ?Carbon
    {
        if ($value === null || $value === '') return null;

        try {
            if (is_numeric($value)) {
                $n = (int)$value;
                if ($n > 2_000_000_000_000) return Carbon::createFromTimestampMs($n);
                if ($n > 946_684_800) return Carbon::createFromTimestamp($n);
                return now()->subSeconds($n);
            }
            return Carbon::parse((string)$value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function stripPort($ip): ?string
    {
        if (!is_string($ip) || $ip === '') return null;
        return str_contains($ip, ':') ? explode(':', $ip, 2)[0] : $ip;
    }

    private function stripCidr($ip): ?string
    {
        if (!is_string($ip) || $ip === '') return null;
        return str_contains($ip, '/') ? explode('/', $ip, 2)[0] : $ip;
    }

    private function enrich(VpnServer $server): array
    {
        return VpnUserConnection::with('vpnUser:id,username')
            ->where('vpn_server_id', $server->id)
            ->where('is_connected', true)
            ->get()
            ->map(fn ($r) => [
                'connection_id' => $r->id,
                'username'      => optional($r->vpnUser)->username ?? 'unknown',
                'client_ip'     => $r->client_ip,
                'virtual_ip'    => $r->virtual_ip,
                'connected_at'  => optional($r->connected_at)?->toIso8601String(),
                'bytes_in'      => (int)$r->bytes_received,
                'bytes_out'     => (int)$r->bytes_sent,
                'server_name'   => $server->name,
                'protocol'      => $r->protocol,
                'session_key'   => $r->session_key,
                'public_key'    => $r->public_key,
            ])->values()->all();
    }

    /**
     * Enforce device limits for all users on this server.
     * Disconnects oldest connections when user exceeds max_connections.
     * Actually kills the sessions on the VPN server.
     */
    private function enforceDeviceLimits(int $serverId, Carbon $now): void
    {
        // Get all users with active connections on this server
        $userIds = VpnUserConnection::where('vpn_server_id', $serverId)
            ->where('is_connected', true)
            ->distinct()
            ->pluck('vpn_user_id')
            ->unique();

        foreach ($userIds as $userId) {
            $user = VpnUser::find($userId);
            
            if (!$user) {
                continue;
            }

            // Skip if unlimited (0) or no limit set
            if ((int) $user->max_connections === 0) {
                continue;
            }

            // Get all active connections for this user across ALL servers
            $activeConnections = VpnUserConnection::with('vpnServer')
                ->where('vpn_user_id', $userId)
                ->where('is_connected', true)
                ->orderBy('connected_at', 'asc') // oldest first
                ->get();

            $maxAllowed = (int) $user->max_connections;
            $currentCount = $activeConnections->count();

            if ($currentCount <= $maxAllowed) {
                continue; // within limit
            }

            // Disconnect oldest connections exceeding the limit
            $toDisconnect = $currentCount - $maxAllowed;

            Log::channel('vpn')->warning(sprintf(
                'DEVICE_LIMIT: User %s (%d) exceeded limit: %d/%d devices - disconnecting %d oldest session(s)',
                $user->username,
                $userId,
                $currentCount,
                $maxAllowed,
                $toDisconnect
            ));

            foreach ($activeConnections->take($toDisconnect) as $conn) {
                // Kill the actual session on the VPN server
                $this->killSession($conn);

                // Update database to reflect disconnection
                $conn->update([
                    'is_connected'     => false,
                    'disconnected_at'  => $now,
                    'session_duration' => $conn->connected_at ? $now->diffInSeconds($conn->connected_at) : null,
                ]);

                Log::channel('vpn')->info(sprintf(
                    'DEVICE_LIMIT: ✂️ Killed %s session %s for user %s on server %s',
                    $conn->protocol,
                    $conn->session_key,
                    $user->username,
                    optional($conn->vpnServer)->name ?? "#{$conn->vpn_server_id}"
                ));
            }

            // Update user online status
            VpnUserConnection::updateUserOnlineStatusIfNoActiveConnections($userId);
        }
    }

    /**
     * Actually kill a VPN session on the server (not just in database).
     */
    private function killSession(VpnUserConnection $conn): void
    {
        try {
            $server = $conn->vpnServer;
            if (!$server) {
                Log::channel('vpn')->warning("DEVICE_LIMIT: Cannot kill session - server not found for connection #{$conn->id}");
                return;
            }

            if ($conn->protocol === 'WIREGUARD') {
                // For WireGuard: remove peer from server
                $publicKey = $conn->public_key;
                if (!$publicKey) {
                    Log::channel('vpn')->warning("DEVICE_LIMIT: Cannot kill WG session - no public key");
                    return;
                }

                $interface = $server->wg_interface ?? 'wg0';
                $command = sprintf(
                    'wg set %s peer %s remove 2>/dev/null || true',
                    escapeshellarg($interface),
                    escapeshellarg($publicKey)
                );

                $this->executeRemoteCommand($server, $command, 5);
                
                Log::channel('vpn')->debug("DEVICE_LIMIT: WireGuard peer {$publicKey} removed from {$interface}");

            } else {
                // For OpenVPN: kill client via management interface
                $mgmtPort = $conn->mgmt_port ?: 7505;
                $clientId = $conn->client_id;

                if ($clientId === null) {
                    Log::channel('vpn')->warning("DEVICE_LIMIT: Cannot kill OpenVPN session - no client_id");
                    return;
                }

                // Send kill command to OpenVPN management interface
                $command = sprintf(
                    'echo "kill %s" | nc 127.0.0.1 %d 2>/dev/null || true',
                    escapeshellarg((string)$clientId),
                    $mgmtPort
                );

                $this->executeRemoteCommand($server, $command, 5);
                
                Log::channel('vpn')->debug("DEVICE_LIMIT: OpenVPN client {$clientId} killed on port {$mgmtPort}");
            }
        } catch (\Throwable $e) {
            Log::channel('vpn')->error(sprintf(
                'DEVICE_LIMIT: Failed to kill session %s: %s',
                $conn->session_key,
                $e->getMessage()
            ));
        }
    }
}