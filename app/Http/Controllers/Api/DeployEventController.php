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

class DeployEventController extends Controller
{
    // How long a connection can be "missing" from snapshots before we mark it offline.
    // Must be >= push interval.
    private const OFFLINE_GRACE = 300; // 5 minutes

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

        $status = strtolower($data['status']);
        $ts     = $data['ts'] ?? now()->toIso8601String();
        $raw    = $data['message'] ?? $status;

        // Only process mgmt snapshots
        if ($status !== 'mgmt') {
            Log::channel('vpn')->debug("DeployEventController: non-mgmt status='{$status}' for server #{$server->id}");
            return response()->json(['ok' => true]);
        }

        $now = now();

        // 1) Normalise
        $incomingAll = $this->normaliseIncoming($data);

        // 2) Filter garbage
        $incoming = array_values(array_filter($incomingAll, function (array $r) {
            $name = trim((string)($r['username'] ?? ''));
            $p    = strtolower((string)($r['proto'] ?? 'openvpn'));

            if ($name === '' || strcasecmp($name, 'UNDEF') === 0 || strcasecmp($name, 'unknown') === 0) {
                return false;
            }

            // OpenVPN usernames
            if ($p === 'openvpn') {
                return (bool) preg_match('/^[A-Za-z0-9._-]{3,64}$/', $name);
            }

            // WireGuard usernames are usually public keys when you push snapshots that way
            if ($p === 'wireguard') {
                return (bool) preg_match('#^[A-Za-z0-9+/=]{32,80}$#', $name);
            }

            return true;
        }));

        Log::channel('vpn')->debug(sprintf(
            'MGMT EVENT server=%d ts=%s incoming=%d filtered=%d [%s]',
            $server->id,
            $ts,
            count($incomingAll),
            count($incoming),
            implode(',', array_column($incoming, 'username'))
        ));

        // Column presence checks (keeps this controller safe across DB versions)
        $connHasProtocol  = Schema::hasColumn('vpn_user_connections', 'protocol');
        $connHasClientId  = Schema::hasColumn('vpn_user_connections', 'client_id');
        $connHasPubKey    = Schema::hasColumn('vpn_user_connections', 'public_key');
        $connHasMgmtPort  = Schema::hasColumn('vpn_user_connections', 'mgmt_port');

        $userHasWgKey     = Schema::hasColumn('vpn_users', 'wireguard_public_key');
        $userHasLastProto = Schema::hasColumn('vpn_users', 'last_protocol');

        DB::transaction(function () use (
            $server,
            $incoming,
            $now,
            $connHasProtocol,
            $connHasClientId,
            $connHasPubKey,
            $connHasMgmtPort,
            $userHasWgKey,
            $userHasLastProto
        ) {
            // Split input by proto
            $openvpn   = [];
            $wireguard = [];

            foreach ($incoming as $c) {
                $proto = $this->normalizeProto($c['proto'] ?? null);
                if ($proto === 'WIREGUARD') {
                    $wireguard[] = $c;
                } else {
                    $openvpn[] = $c;
                }
            }

            // OpenVPN map username -> id
            $ovpnNames = array_values(array_unique(array_column($openvpn, 'username')));
            $idByName  = !empty($ovpnNames)
                ? VpnUser::whereIn('username', $ovpnNames)->pluck('id', 'username')
                : collect();

            // WireGuard map wireguard_public_key -> id
            $wgKeys = [];
            foreach ($wireguard as $c) {
                if (!empty($c['public_key'])) $wgKeys[] = $c['public_key'];
                if (!empty($c['username']))   $wgKeys[] = $c['username']; // agent may send key as username
            }
            $wgKeys = array_values(array_unique(array_filter($wgKeys)));

            $idByWgKey = (!empty($wgKeys) && $userHasWgKey)
                ? VpnUser::whereIn('wireguard_public_key', $wgKeys)->pluck('id', 'wireguard_public_key')
                : collect();

            // We'll track exactly which connection rows were seen this cycle
            $touchedConnectionIds = [];

            foreach ($incoming as $c) {
                $username  = trim((string)($c['username'] ?? ''));
                $proto     = $this->normalizeProto($c['proto'] ?? null);

                $publicKey = $c['public_key'] ?? null;
                $clientId  = isset($c['client_id']) ? (int) $c['client_id'] : null;
                $mgmtPort  = isset($c['mgmt_port']) ? (int) $c['mgmt_port'] : null;

                // Resolve vpn_user_id
                $uid = null;

                if ($proto === 'WIREGUARD') {
                    $key = $publicKey ?: $username;
                    if ($key && isset($idByWgKey[$key])) {
                        $uid = (int) $idByWgKey[$key];
                        // normalize publicKey for storage
                        $publicKey = $publicKey ?: $key;
                    }
                } else {
                    $uid = isset($idByName[$username]) ? (int) $idByName[$username] : null;
                }

                if (!$uid) {
                    Log::channel('vpn')->notice("MGMT: skipping unknown {$proto} user '{$username}' on server {$server->id}");
                    continue;
                }

                $connectedAtParsed = null;
                if (!empty($c['connected_at'])) {
                    $connectedAtParsed = $this->parseConnectedAt($c['connected_at']);
                }

                // Build a "session identity" so we DON'T merge multiple sessions into one row.
                // - OpenVPN: client_id is the session identity
                // - WireGuard: public_key is the identity
                // Fallback (if columns missing): old behavior (merges by user+server)
                $lookup = [
                    'vpn_user_id'   => $uid,
                    'vpn_server_id' => $server->id,
                ];

                if ($connHasProtocol) {
                    $lookup['protocol'] = $proto; // OPENVPN / WIREGUARD
                }

                if ($proto === 'OPENVPN' && $connHasClientId && $clientId !== null) {
                    $lookup['client_id'] = $clientId;
                }

                if ($proto === 'WIREGUARD' && $connHasPubKey && !empty($publicKey)) {
                    $lookup['public_key'] = $publicKey;
                }

                /** @var VpnUserConnection $row */
                $row = VpnUserConnection::firstOrNew($lookup);

                $wasConnected = (bool) $row->is_connected;

                // Only set connected_at when session starts or missing.
                if (!$wasConnected) {
                    $row->connected_at    = $connectedAtParsed ?: $now;
                    $row->disconnected_at = null;
                } elseif (empty($row->connected_at) && $connectedAtParsed) {
                    $row->connected_at = $connectedAtParsed;
                }

                $row->is_connected = true;

                if (!empty($c['client_ip'])) {
                    $row->client_ip = $c['client_ip'];
                }
                if (!empty($c['virtual_ip'])) {
                    $row->virtual_ip = $c['virtual_ip'];
                }

                if (array_key_exists('bytes_in', $c)) {
                    $row->bytes_received = (int) $c['bytes_in'];
                }
                if (array_key_exists('bytes_out', $c)) {
                    $row->bytes_sent = (int) $c['bytes_out'];
                }

                // Persist extra enforcement fields if columns exist
                if ($connHasClientId && $proto === 'OPENVPN' && $clientId !== null) {
                    $row->client_id = $clientId;
                }
                if ($connHasPubKey && $proto === 'WIREGUARD' && !empty($publicKey)) {
                    $row->public_key = $publicKey;
                }
                if ($connHasMgmtPort && $proto === 'OPENVPN' && $mgmtPort !== null) {
                    $row->mgmt_port = $mgmtPort;
                }

                $row->save();
                $touchedConnectionIds[] = $row->id;

                // Update vpn_users summary (online + last ip/proto)
                $userUpdate = [
                    'is_online' => true,
                    'last_ip'   => $row->client_ip,
                ];
                if ($userHasLastProto) {
                    $userUpdate['last_protocol'] = $proto;
                }
                VpnUser::whereKey($uid)->update($userUpdate);
            }

            // Disconnect sessions missing for longer than OFFLINE_GRACE (no flicker)
            $graceAgo = $now->copy()->subSeconds(self::OFFLINE_GRACE);

            $toDisconnect = VpnUserConnection::query()
                ->where('vpn_server_id', $server->id)
                ->where('is_connected', true)
                ->when(!empty($touchedConnectionIds), fn ($q) => $q->whereNotIn('id', $touchedConnectionIds))
                ->get();

            foreach ($toDisconnect as $row) {
                if ($row->updated_at && $row->updated_at->gt($graceAgo)) {
                    continue;
                }

                $row->update([
                    'is_connected'     => false,
                    'disconnected_at'  => $now,
                    'session_duration' => $row->connected_at
                        ? $now->diffInSeconds($row->connected_at)
                        : null,
                ]);

                // Only set user offline if they have no other active sessions
                VpnUserConnection::updateUserOnlineStatusIfNoActiveConnections($row->vpn_user_id);
            }

            // Persist server aggregates
            $liveKnown = VpnUserConnection::query()
                ->where('vpn_server_id', $server->id)
                ->where('is_connected', true)
                ->count();

            $update = [];
            if (Schema::hasColumn('vpn_servers', 'online_users')) {
                $update['online_users'] = $liveKnown;
            }
            if (Schema::hasColumn('vpn_servers', 'last_mgmt_at')) {
                $update['last_mgmt_at'] = $now;
            }
            if (!empty($update)) {
                $server->forceFill($update)->saveQuietly();
            }
        });

        // Enriched snapshot for broadcast + response
        $enriched = $this->enrichFromDb($server);

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

    /* ───────────────── helpers ───────────────── */

    private function normalizeProto(?string $v): string
    {
        $p = strtolower((string) $v);

        return match (true) {
            str_starts_with($p, 'wire')          => 'WIREGUARD',
            str_starts_with($p, 'ovpn'),
            str_starts_with($p, 'openvpn')       => 'OPENVPN',
            $p === ''                            => 'OPENVPN',
            default                              => strtoupper($p),
        };
    }

    private function normaliseIncoming(array $data): array
    {
        $out = [];

        if (!empty($data['users']) && is_array($data['users'])) {
            foreach ($data['users'] as $u) {
                $out[] = $this->normaliseUserItem($u);
            }
        } elseif (!empty($data['cn_list'])) {
            foreach (explode(',', $data['cn_list']) as $name) {
                $name = trim($name);
                if ($name !== '') {
                    $out[] = [
                        'username' => $name,
                        'proto'    => 'openvpn',
                    ];
                }
            }
        }

        // De-duplicate by session identity, NOT just (proto, username)
        $seen = [];
        return array_values(array_filter($out, function ($r) use (&$seen) {
            $name  = $r['username'] ?? null;
            $proto = strtolower((string)($r['proto'] ?? 'openvpn'));
            if (!$name) return false;

            // Prefer session key:
            // - OpenVPN: client_id (if present)
            // - WireGuard: public_key (or username if pubkey used as username)
            $sid = null;

            if ($proto === 'openvpn') {
                $sid = $r['client_id'] ?? null;
            } elseif ($proto === 'wireguard') {
                $sid = $r['public_key'] ?? $name;
            }

            $key = $proto . '|' . $name . '|' . ($sid ?? 'nosid');

            if (isset($seen[$key])) return false;
            $seen[$key] = true;

            return true;
        }));
    }

    private function normaliseUserItem($u): array
    {
        if (is_string($u)) {
            $u = ['username' => $u];
        }

        $u = (array) $u;

        // Accept both "proto" and "protocol"
        $proto = strtolower((string)($u['proto'] ?? $u['protocol'] ?? 'openvpn'));
        $protoNorm = $this->normalizeProto($proto);

        $username = $u['username']
            ?? $u['cn']
            ?? $u['CommonName']
            ?? ($protoNorm === 'WIREGUARD'
                ? ($u['public_key'] ?? $u['pubkey'] ?? 'unknown')
                : 'unknown');

        $clientIp = $u['client_ip']
            ?? $u['RealAddress']
            ?? $u['real_ip']
            ?? null;

        if (is_string($clientIp) && str_contains($clientIp, ':')) {
            $clientIp = explode(':', $clientIp, 2)[0];
        }

        $virt = $u['virtual_ip']
            ?? $u['VirtualAddress']
            ?? $u['virtual_address']
            ?? null;

        if (is_string($virt) && str_contains($virt, '/')) {
            $virt = explode('/', $virt, 2)[0];
        }

        $connectedAt = $u['connected_at']
            ?? $u['ConnectedSince']
            ?? $u['connected_since']
            ?? $u['Connected Since (time_t)']
            ?? $u['connected_seconds']
            ?? null;

        $bytesIn = (int) (
            $u['bytes_in']
            ?? $u['BytesReceived']
            ?? $u['bytes_received']
            ?? 0
        );

        $bytesOut = (int) (
            $u['bytes_out']
            ?? $u['BytesSent']
            ?? $u['bytes_sent']
            ?? 0
        );

        // These are critical for enforcement later
        $clientId = isset($u['client_id']) ? (int) $u['client_id'] : null;
        $mgmtPort = isset($u['mgmt_port']) ? (int) $u['mgmt_port'] : null;
        $pubKey   = $u['public_key'] ?? $u['pubkey'] ?? null;

        return [
            'username'     => (string) $username,
            'public_key'   => $pubKey,
            'client_id'    => $clientId,
            'mgmt_port'    => $mgmtPort,
            'client_ip'    => $clientIp ?: null,
            'virtual_ip'   => $virt ?: null,
            'connected_at' => $this->connectedAtToIso($connectedAt),
            'bytes_in'     => $bytesIn,
            'bytes_out'    => $bytesOut,
            // Keep proto as a simple string for filtering/dedupe
            'proto'        => ($protoNorm === 'WIREGUARD') ? 'wireguard' : 'openvpn',
        ];
    }

    private function connectedAtToIso($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            if (is_numeric($value)) {
                $n = (int) $value;

                if ($n > 2_000_000_000_000) {
                    return Carbon::createFromTimestampMs($n)->toIso8601String();
                }

                // epoch seconds
                if ($n > 946_684_800) {
                    return Carbon::createFromTimestamp($n)->toIso8601String();
                }

                // seconds ago
                return now()->subSeconds($n)->toIso8601String();
            }

            return Carbon::parse((string) $value)->toIso8601String();
        } catch (\Throwable) {
            return null;
        }
    }

    private function parseConnectedAt($value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            if (is_numeric($value)) {
                $n = (int) $value;

                if ($n > 2_000_000_000_000) {
                    return Carbon::createFromTimestampMs($n);
                }

                if ($n > 946_684_800) {
                    return Carbon::createFromTimestamp($n);
                }

                return now()->subSeconds($n);
            }

            return Carbon::parse((string) $value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function enrichFromDb(VpnServer $server): array
    {
        $rows = VpnUserConnection::query()
            ->with('vpnUser:id,username')
            ->where('vpn_server_id', $server->id)
            ->where('is_connected', true)
            ->get();

        $hasProtocol = Schema::hasColumn('vpn_user_connections', 'protocol');
        $hasClientId = Schema::hasColumn('vpn_user_connections', 'client_id');
        $hasPubKey   = Schema::hasColumn('vpn_user_connections', 'public_key');

        return $rows->map(function (VpnUserConnection $r) use ($server, $hasProtocol, $hasClientId, $hasPubKey) {
            return [
                'connection_id' => $r->id,
                'username'      => optional($r->vpnUser)->username ?? 'unknown',
                'client_ip'     => $r->client_ip,
                'virtual_ip'    => $r->virtual_ip,
                'connected_at'  => optional($r->connected_at)?->toIso8601String(),
                'bytes_in'      => (int) $r->bytes_received,
                'bytes_out'     => (int) $r->bytes_sent,
                'server_name'   => $server->name,
                'protocol'      => $hasProtocol && $r->protocol ? strtoupper($r->protocol) : null,
                'client_id'     => $hasClientId ? $r->client_id : null,
                'public_key'    => $hasPubKey ? $r->public_key : null,
            ];
        })->values()->all();
    }
}