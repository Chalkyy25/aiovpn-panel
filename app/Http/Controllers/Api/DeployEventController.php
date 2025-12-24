<?php

namespace App\Http\Controllers\Api;

use App\Events\ServerMgmtEvent;
use App\Http\Controllers\Controller;
use App\Models\VpnServer;
use App\Models\VpnUser;
use App\Models\VpnUserConnection;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * OpenVPN Event Controller
 *
 * Single responsibility:
 * - Ingest OpenVPN mgmt snapshots
 * - Upsert OPENVPN connections only
 * - Disconnect missing OPENVPN sessions only (grace window)
 *
 * Never reads/writes WireGuard rows.
 */
class DeployEventController extends Controller
{
    /** seconds */
    private const OFFLINE_GRACE = 300;

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
            'OVPN MGMT EVENT server=%d ts=%s incoming=%d [%s]',
            $server->id,
            $ts,
            count($incoming),
            implode(',', array_slice(array_column($incoming, 'username'), 0, 50))
        ));

        DB::transaction(function () use ($server, $incoming, $now) {
            // Map usernames -> vpn_user_id (only those in payload)
            $idByName = $this->buildUserMap($incoming);

            $touched = [];

            foreach ($incoming as $c) {
                $username = trim((string) ($c['username'] ?? ''));
                $clientId = array_key_exists('client_id', $c) ? (int) $c['client_id'] : null;
                $mgmtPort = array_key_exists('mgmt_port', $c) ? (int) $c['mgmt_port'] : null;

                if ($username === '' || !isset($idByName[$username])) {
                    Log::channel('vpn')->notice("OVPN MGMT: unknown user='{$username}' server={$server->id}");
                    continue;
                }

                // OpenVPN must have an identity; without client_id we cannot form a stable session key.
                $sessionKey = $this->sessionKey($username, $clientId, $mgmtPort);
                if (!$sessionKey) {
                    Log::channel('vpn')->notice("OVPN MGMT: missing identity user='{$username}' server={$server->id}");
                    continue;
                }

                $uid = (int) $idByName[$username];
                $touched[] = $sessionKey;

                // Upsert OPENVPN connection row
                $row = VpnUserConnection::firstOrNew([
                    'vpn_server_id' => $server->id,
                    'session_key'   => $sessionKey,
                ]);

                $isNew = !$row->exists;

                $incomingConnectedAt = !empty($c['connected_at'])
                    ? $this->parseTime($c['connected_at'])
                    : null;

                $row->fill([
                    'vpn_user_id'     => $uid,
                    'protocol'        => 'OPENVPN',
                    'client_id'       => $clientId,
                    'mgmt_port'       => $mgmtPort ?: 7505,
                    'is_connected'    => true,
                    'disconnected_at' => null,

                    'client_ip'       => $c['client_ip'] ?? $row->client_ip,
                    'virtual_ip'      => $c['virtual_ip'] ?? $row->virtual_ip,

                    'bytes_received'  => array_key_exists('bytes_in', $c)
                        ? (int) $c['bytes_in']
                        : (int) ($row->bytes_received ?? 0),

                    'bytes_sent'      => array_key_exists('bytes_out', $c)
                        ? (int) $c['bytes_out']
                        : (int) ($row->bytes_sent ?? 0),

                    // mgmt snapshot implies "seen now"
                    'seen_at'         => $now,
                ]);

                if ($incomingConnectedAt) {
                    $row->connected_at = $incomingConnectedAt;
                } elseif ($isNew || !$row->connected_at) {
                    $row->connected_at = $now;
                }

                $row->save();

                // Update user status (OPENVPN)
                $userUpdate = [
                    'is_online' => true,
                    'last_ip'   => $row->client_ip,
                ];

                if (Schema::hasColumn('vpn_users', 'last_protocol')) {
                    $userUpdate['last_protocol'] = 'OPENVPN';
                }

                VpnUser::whereKey($uid)->update($userUpdate);
            }

            // Disconnect missing OPENVPN sessions only
            $this->disconnectMissingOpenVpn($server->id, $touched, $now);

            // Aggregates (OPTIONAL: if you want total across protocols, don’t filter here)
            $liveOpenVpn = VpnUserConnection::where('vpn_server_id', $server->id)
                ->where('protocol', 'OPENVPN')
                ->where('is_connected', true)
                ->count();

            $serverUpdate = [];
            if (Schema::hasColumn('vpn_servers', 'online_users')) $serverUpdate['online_users'] = $liveOpenVpn;
            if (Schema::hasColumn('vpn_servers', 'last_sync_at')) $serverUpdate['last_sync_at'] = $now;

            if ($serverUpdate) {
                $server->forceFill($serverUpdate)->saveQuietly();
            }
        });

        $enriched = $this->enrichOpenVpn($server);

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

    /**
     * Normalize payload into a stable internal array.
     */
    private function normalizeIncoming(array $data): array
    {
        $out = [];

        if (!empty($data['users']) && is_array($data['users'])) {
            foreach ($data['users'] as $u) {
                $u = is_string($u) ? ['username' => $u] : (array) $u;

                $username = (string) ($u['username'] ?? $u['cn'] ?? $u['CommonName'] ?? 'unknown');

                $out[] = [
                    'proto'        => 'openvpn',
                    'username'     => $username,
                    'client_id'    => isset($u['client_id']) ? (int) $u['client_id'] : null,
                    'mgmt_port'    => isset($u['mgmt_port']) ? (int) $u['mgmt_port'] : null,
                    'client_ip'    => $this->stripPort($u['client_ip'] ?? $u['RealAddress'] ?? null),
                    'virtual_ip'   => $this->stripCidr($u['virtual_ip'] ?? $u['VirtualAddress'] ?? null),
                    'connected_at' => $u['connected_at'] ?? $u['ConnectedSince'] ?? null,
                    'bytes_in'     => (int) ($u['bytes_in'] ?? $u['BytesReceived'] ?? 0),
                    'bytes_out'    => (int) ($u['bytes_out'] ?? $u['BytesSent'] ?? 0),
                ];
            }
        } elseif (!empty($data['cn_list'])) {
            foreach (explode(',', (string) $data['cn_list']) as $name) {
                $name = trim($name);
                if ($name !== '') {
                    $out[] = ['proto' => 'openvpn', 'username' => $name, 'client_id' => -1];
                }
            }
        }

        // Dedupe + validate
        $seen = [];
        return array_values(array_filter($out, function ($r) use (&$seen) {
            $name = trim((string) ($r['username'] ?? ''));

            if ($name === '' || strcasecmp($name, 'unknown') === 0 || strcasecmp($name, 'UNDEF') === 0) {
                return false;
            }

            // allow only simple usernames
            if (!preg_match('/^[A-Za-z0-9._-]{3,64}$/', $name)) return false;

            $k = "ovpn|{$name}|" . ($r['client_id'] ?? 'nocid');
            if (isset($seen[$k])) return false;

            $seen[$k] = true;
            return true;
        }));
    }

    private function buildUserMap(array $incoming): array
    {
        $names = array_values(array_unique(array_filter(array_column($incoming, 'username'))));

        return $names
            ? VpnUser::whereIn('username', $names)->pluck('id', 'username')->all()
            : [];
    }

    private function sessionKey(string $username, ?int $clientId, ?int $mgmtPort): ?string
    {
        if ($clientId === null) return null;
        $mp = $mgmtPort ?: 7505;
        return "ovpn:{$mp}:{$clientId}:{$username}";
    }

    /**
     * CRITICAL: disconnect OPENVPN only. Never touch WireGuard rows.
     */
    private function disconnectMissingOpenVpn(int $serverId, array $touchedSessionKeys, Carbon $now): void
    {
        $graceAgo = $now->copy()->subSeconds(self::OFFLINE_GRACE);

        $q = VpnUserConnection::where('vpn_server_id', $serverId)
            ->where('protocol', 'OPENVPN')
            ->where('is_connected', true);

        if (!empty($touchedSessionKeys)) {
            $q->whereNotIn('session_key', $touchedSessionKeys);
        }

        foreach ($q->get() as $row) {
            // Only disconnect if it's been missing long enough
            if ($row->updated_at && $row->updated_at->gt($graceAgo)) {
                continue;
            }

            $row->update([
                'is_connected'     => false,
                'disconnected_at'  => $now,
                'session_duration' => $row->connected_at ? $now->diffInSeconds($row->connected_at) : null,
            ]);

            VpnUserConnection::updateUserOnlineStatusIfNoActiveConnections($row->vpn_user_id);
        }
    }

    private function parseTime($value): ?Carbon
    {
        if ($value === null || $value === '') return null;

        try {
            if (is_numeric($value)) {
                $n = (int) $value;
                if ($n > 2_000_000_000_000) return Carbon::createFromTimestampMs($n);
                if ($n > 946_684_800) return Carbon::createFromTimestamp($n);
                return now()->subSeconds($n);
            }
            return Carbon::parse((string) $value);
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
     * Broadcast OPENVPN-only snapshot.
     */
    private function enrich(VpnServer $server): array
{
    $hasSeenAt = Schema::hasColumn('vpn_user_connections', 'seen_at');

    return VpnUserConnection::with('vpnUser:id,username')
        ->where('vpn_server_id', $server->id)
        ->where('is_connected', true)
        ->whereIn('protocol', ['OPENVPN', 'WIREGUARD']) // ✅ critical
        ->get()
        ->map(function ($r) use ($server, $hasSeenAt) {
            $proto = strtoupper((string) $r->protocol);

            return [
                'connection_id' => $r->id,
                'username'      => optional($r->vpnUser)->username ?? 'unknown',
                'client_ip'     => $r->client_ip,
                'virtual_ip'    => $r->virtual_ip,
                'connected_at'  => optional($r->connected_at)?->toIso8601String(),
                'seen_at'       => $hasSeenAt
                    ? optional($r->seen_at)?->toIso8601String()
                    : optional($r->updated_at)?->toIso8601String(),
                'bytes_in'      => (int) $r->bytes_received,
                'bytes_out'     => (int) $r->bytes_sent,
                'server_name'   => $server->name,
                'protocol'      => $proto,
                'session_key'   => $r->session_key,
                'client_id'     => $r->client_id,
                'mgmt_port'     => $r->mgmt_port,
                'public_key'    => $r->public_key, // ✅ helps WG rows
            ];
        })
        ->values()
        ->all();
}
}