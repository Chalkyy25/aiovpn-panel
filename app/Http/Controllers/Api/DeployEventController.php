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
use Illuminate\Support\Facades\Schema;

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
            'cn_list' => 'nullable|string', // legacy ovpn
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
            implode(',', array_slice(array_column($incoming, 'username'), 0, 50))
        ));

        DB::transaction(function () use ($server, $incoming, $now) {

            [$idByOvpnName, $uidByWgPub] = $this->buildUserMaps($incoming);

            $touchedKeys = [];

            foreach ($incoming as $c) {
                $proto     = $this->proto($c['proto'] ?? null); // OPENVPN / WIREGUARD
                $username  = trim((string)($c['username'] ?? ''));
                $publicKey = $c['public_key'] ?? null;
                $clientId  = isset($c['client_id']) ? (int)$c['client_id'] : null;
                $mgmtPort  = isset($c['mgmt_port']) ? (int)$c['mgmt_port'] : null;

                // ---- Resolve user id ----
                $uid = null;

                if ($proto === 'WIREGUARD') {
                    // Agent may send pubkey as username. We accept either.
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

                $connectedAt = !empty($c['connected_at']) ? $this->parseTime($c['connected_at']) : null;

                // ✅ Upsert by server + session_key (matches your UNIQUE(vpn_server_id, session_key))
                $row = VpnUserConnection::firstOrNew([
    'vpn_server_id' => $server->id,
    'session_key'   => $sessionKey,
]);

$isNew = !$row->exists;

$row->fill([
    'vpn_user_id'     => $uid,
    'protocol'        => $proto,
    'public_key'      => $proto === 'WIREGUARD' ? $publicKey : null,
    'client_id'       => $proto === 'OPENVPN' ? $clientId : null,
    'mgmt_port'       => $proto === 'OPENVPN' ? ($mgmtPort ?: 7505) : null,
    'is_connected'    => true,
    'disconnected_at' => null,
    'client_ip'       => $c['client_ip'] ?? null,
    'virtual_ip'      => $c['virtual_ip'] ?? null,
    'bytes_received'  => (int) ($c['bytes_in'] ?? 0),
    'bytes_sent'      => (int) ($c['bytes_out'] ?? 0),
]);

// ✅ IMPORTANT: only set connected_at ONCE per session
if ($isNew && !$row->connected_at) {
    $row->connected_at = $connectedAt ?? $now;
}

$row->save();
            
                // user summary
                $userUpdate = [
                    'is_online' => true,
                    'last_ip'   => $row->client_ip,
                ];
                if (Schema::hasColumn('vpn_users', 'last_protocol')) {
                    $userUpdate['last_protocol'] = $proto;
                }
                VpnUser::whereKey($uid)->update($userUpdate);
            }

            // mark missing sessions offline (grace window)
            $this->disconnectMissing($server->id, $touchedKeys, $now);

            // server aggregates (guard columns)
            $liveKnown = VpnUserConnection::where('vpn_server_id', $server->id)
                ->where('is_connected', true)
                ->count();

            $serverUpdate = [];
            if (Schema::hasColumn('vpn_servers', 'online_users')) $serverUpdate['online_users'] = $liveKnown;
            if (Schema::hasColumn('vpn_servers', 'last_mgmt_at')) $serverUpdate['last_mgmt_at'] = $now;
            if ($serverUpdate) $server->forceFill($serverUpdate)->saveQuietly();
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
            foreach (explode(',', (string)$data['cn_list']) as $name) {
                $name = trim($name);
                if ($name !== '') {
                    $out[] = ['proto' => 'openvpn', 'username' => $name, 'client_id' => -1];
                }
            }
        }

        // dedupe
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
                $k = "wg|{$key}";
            } else {
                if (!preg_match('/^[A-Za-z0-9._-]{3,64}$/', $name)) return false;
                $k = "ovpn|{$name}|" . ($r['client_id'] ?? 'nocid');
            }

            if (isset($seen[$k])) return false;
            $seen[$k] = true;
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

        // OPENVPN needs a client_id (from status parser)
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
            'seen_at'       => optional($r->updated_at)?->toIso8601String(), // ✅ add this
            'bytes_in'      => (int)$r->bytes_received,
            'bytes_out'     => (int)$r->bytes_sent,
            'server_name'   => $server->name,
            'protocol'      => $r->protocol,
            'session_key'   => $r->session_key,
            'public_key'    => $r->public_key,
        ])->values()->all();
}
}