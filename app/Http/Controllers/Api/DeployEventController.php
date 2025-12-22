<?php

namespace App\Http\Controllers\Api;

use App\Events\ServerMgmtEvent;
use App\Http\Controllers\Controller;
use App\Models\VpnServer;
use App\Models\VpnUser;
use App\Models\VpnUserConnection;
use App\Models\WireguardPeer;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DeployEventController extends Controller
{
    private const OFFLINE_GRACE = 300; // seconds

    public function store(Request $request, VpnServer $server): JsonResponse
    {
        $data = $request->validate([
            'status'  => 'required|string',
            'message' => 'nullable|string',
            'ts'      => 'nullable|string',
            'users'   => 'nullable|array',
            'cn_list' => 'nullable|string', // legacy openvpn
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
            'MGMT EVENT server=%d ts=%s incoming=%d',
            $server->id,
            $ts,
            count($incoming)
        ));

        DB::transaction(function () use ($server, $incoming, $now) {

            // username->vpn_users.id (OpenVPN) and pubkey->vpn_user_id (WireGuard)
            [$idByOvpnName, $uidByWgPub] = $this->buildUserMaps($incoming);

            $touchedKeys = [];
            $touchedUserIds = [];

            foreach ($incoming as $c) {
                $proto     = $this->proto($c['proto'] ?? null); // OPENVPN / WIREGUARD
                $username  = trim((string)($c['username'] ?? ''));
                $publicKey = $c['public_key'] ?? null;
                $clientId  = isset($c['client_id']) ? (int)$c['client_id'] : null;
                $mgmtPort  = isset($c['mgmt_port']) ? (int)$c['mgmt_port'] : null;

                // Resolve uid
                $uid = null;

                if ($proto === 'WIREGUARD') {
                    // your agents sometimes put pubkey in username; normalize it
                    $key = $publicKey ?: $username;
                    $publicKey = $key ?: null;

                    if ($publicKey && isset($uidByWgPub[$publicKey])) {
                        $uid = (int) $uidByWgPub[$publicKey];
                    }
                } else {
                    if ($username && isset($idByOvpnName[$username])) {
                        $uid = (int) $idByOvpnName[$username];
                    }
                }

                if (!$uid) {
                    Log::channel('vpn')->notice("MGMT: unknown {$proto} identity='{$username}' server={$server->id}");
                    continue;
                }

                // Session key (MUST be stable, matches unique index vpn_server_id+session_key)
                $sessionKey = $this->sessionKey($proto, $username, $clientId, $mgmtPort, $publicKey);
                if (!$sessionKey) {
                    Log::channel('vpn')->notice("MGMT: missing session_key proto={$proto} user='{$username}' server={$server->id}");
                    continue;
                }

                $touchedKeys[] = $sessionKey;
                $touchedUserIds[] = $uid;

                $connectedAt = !empty($c['connected_at']) ? $this->parseTime($c['connected_at']) : null;

                // Upsert
                $row = VpnUserConnection::updateOrCreate(
                    [
                        'vpn_server_id' => $server->id,
                        'session_key'   => $sessionKey,
                    ],
                    [
                        'vpn_user_id'     => $uid,
                        'protocol'        => $proto,
                        'is_connected'    => true,
                        'disconnected_at' => null,

                        // Protocol-specific identity fields
                        'public_key' => $proto === 'WIREGUARD' ? $publicKey : null,
                        'client_id'  => $proto === 'OPENVPN' ? $clientId : null,
                        'mgmt_port'  => $proto === 'OPENVPN' ? ($mgmtPort ?: 7505) : null,

                        // Details
                        'connected_at'   => $connectedAt ?? ($this->fallbackConnectedAt($proto, $now)),
                        'client_ip'      => $c['client_ip'] ?? null,
                        'virtual_ip'     => $c['virtual_ip'] ?? null,
                        'bytes_received' => (int)($c['bytes_in'] ?? 0),
                        'bytes_sent'     => (int)($c['bytes_out'] ?? 0),
                    ]
                );

                // Keep user online
                VpnUser::whereKey($uid)->update([
                    'is_online' => true,
                    'last_ip'   => $row->client_ip,
                ]);
            }

            // Mark missing sessions offline (grace)
            $this->disconnectMissing($server->id, $touchedKeys, $now);

            // If any users got disconnected due to missing sessions, ensure is_online is correct
            foreach (array_unique($touchedUserIds) as $uid) {
                VpnUserConnection::updateUserOnlineStatusIfNoActiveConnections((int)$uid);
            }

            // Server aggregates
            $liveKnown = VpnUserConnection::where('vpn_server_id', $server->id)
                ->where('is_connected', true)
                ->count();

            $server->forceFill([
                'online_users' => $liveKnown,
                'last_mgmt_at' => $now,
            ])->saveQuietly();
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

    /* ------------------------ Normalization ------------------------ */

    private function normalizeIncoming(array $data): array
    {
        $out = [];

        if (!empty($data['users']) && is_array($data['users'])) {
            foreach ($data['users'] as $u) {
                $u = is_string($u) ? ['username' => $u] : (array)$u;

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
        } elseif (!empty($data['cn_list'])) {
            // legacy OpenVPN list
            foreach (explode(',', (string)$data['cn_list']) as $name) {
                $name = trim($name);
                if ($name !== '') {
                    $out[] = ['proto' => 'openvpn', 'username' => $name];
                }
            }
        }

        // Filter garbage + dedupe by stable identity
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
                $dedupeKey = "ovpn|{$name}|" . ($r['client_id'] ?? 'nocid') . '|' . ($r['mgmt_port'] ?? 'nomp');
            }

            if (isset($seen[$dedupeKey])) return false;
            $seen[$dedupeKey] = true;
            return true;
        }));
    }

    /* ------------------------ Maps / Identity ------------------------ */

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

        // OPENVPN
        if ($clientId === null) return null;
        $mp = $mgmtPort ?: 7505;
        return "ovpn:{$mp}:{$clientId}:{$username}";
    }

    /* ------------------------ Offline handling ------------------------ */

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

            VpnUserConnection::updateUserOnlineStatusIfNoActiveConnections((int)$row->vpn_user_id);
        }
    }

    /* ------------------------ Misc helpers ------------------------ */

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

    /**
     * WG handshakes are "last handshake", not true "connected since".
     * If your agent sends connected_at, we use it. Otherwise leave it as now (UI friendly).
     */
    private function fallbackConnectedAt(string $proto, Carbon $now): Carbon
    {
        return $now;
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
            ])
            ->values()
            ->all();
    }
}