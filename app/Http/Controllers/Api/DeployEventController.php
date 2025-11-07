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
    public function store(Request $request, VpnServer $server): JsonResponse
    {
        $data = $request->validate([
            'status'   => 'required|string',   // e.g. "mgmt"
            'message'  => 'nullable|string',
            'ts'       => 'nullable|string',
            'users'    => 'nullable|array',    // array<string|object>
            'cn_list'  => 'nullable|string',
            'clients'  => 'nullable|integer',
        ]);

        $status = strtolower($data['status']);
        $ts     = $data['ts'] ?? now()->toIso8601String();
        $raw    = $data['message'] ?? $status;

        // We only care about mgmt-style events with live sessions
        if ($status !== 'mgmt') {
            Log::channel('vpn')->debug("DeployEventController: non-mgmt status='{$status}' for server #{$server->id}");
            return response()->json(['ok' => true]);
        }

        // Normalise → [{ username, client_ip?, virtual_ip?, bytes_in?, bytes_out?, connected_at?, proto? }]
        $incomingAll = $this->normaliseIncoming($data);

        // Filter to valid usernames/keys
        $incoming = array_values(array_filter($incomingAll, function ($r) {
            $name  = trim((string)($r['username'] ?? ''));
            $proto = strtolower($r['proto'] ?? 'openvpn');

            if ($name === '' || strcasecmp($name, 'UNDEF') === 0 || strcasecmp($name, 'unknown') === 0) {
                return false;
            }

            // OpenVPN: classic username policy
            if ($proto === 'openvpn') {
                return (bool) preg_match('/^[A-Za-z0-9._-]{3,64}$/', $name);
            }

            // WireGuard: allow base64-ish public keys
            if ($proto === 'wireguard') {
                return (bool) preg_match('#^[A-Za-z0-9+/=]{32,80}$#', $name);
            }

            // Default fallback: be conservative
            return (bool) preg_match('/^[A-Za-z0-9._-]{3,64}$/', $name);
        }));

        $now = now();

        Log::channel('vpn')->debug(sprintf(
            'MGMT EVENT server=%d ts=%s incoming=%d filtered=%d [%s]',
            $server->id,
            $ts,
            count($incomingAll),
            count($incoming),
            implode(',', array_column($incoming, 'username'))
        ));

        DB::transaction(function () use ($server, $incoming, $now) {

            // Split incoming by protocol for proper matching
            $openvpn = [];
            $wireguard = [];

            foreach ($incoming as $c) {
                $proto = strtolower($c['proto'] ?? 'openvpn');
                if ($proto === 'wireguard') {
                    $wireguard[] = $c;
                } else {
                    $openvpn[] = $c;
                }
            }

            // Map: OpenVPN by username
            $ovpnNames = array_column($openvpn, 'username');
            $idByName = !empty($ovpnNames)
                ? VpnUser::whereIn('username', $ovpnNames)->pluck('id', 'username')
                : collect();

            // Map: WireGuard by wg_public_key (username field carries pubkey)
            // Map: WireGuard by wireguard_public_key using explicit public_key from payload
            $wgKeys = array_values(array_filter(array_column($wireguard, 'public_key')));
            $idByWgKey = (!empty($wgKeys) && Schema::hasColumn('vpn_users', 'wireguard_public_key'))
                ? VpnUser::whereIn('wireguard_public_key', $wgKeys)->pluck('id', 'wireguard_public_key')
                : collect();
            
            $stillConnectedUserIds = [];

            foreach ($incoming as $c) {
                $username = trim((string) $c['username']);
                $proto    = strtolower($c['proto'] ?? 'openvpn');

                $publicKey = $c['public_key'] ?? null;

                if ($proto === 'wireguard' && $publicKey) {
                    $uid = $idByWgKey[$publicKey] ?? null;
                } else {
                    $uid = $idByName[$username] ?? null;
                }

                // Skip unknown users (we only track panel-created users)
                if (!$uid) {
                    Log::channel('vpn')->notice("MGMT: skipping unknown {$proto} user '{$username}' on server {$server->id}");
                    continue;
                }

                $stillConnectedUserIds[] = $uid;

                /** @var VpnUserConnection $row */
                $row = VpnUserConnection::firstOrCreate([
                    'vpn_user_id'   => $uid,
                    'vpn_server_id' => $server->id,
                ]);

                // If previously disconnected, mark fresh session
                if (!$row->is_connected) {
                    $row->connected_at    = $now;
                    $row->disconnected_at = null;
                }

                $row->is_connected = true;

                if (!empty($c['client_ip']))  $row->client_ip  = $c['client_ip'];
                if (!empty($c['virtual_ip'])) $row->virtual_ip = $c['virtual_ip'];

                if (array_key_exists('bytes_in', $c))  $row->bytes_received = (int) $c['bytes_in'];
                if (array_key_exists('bytes_out', $c)) $row->bytes_sent     = (int) $c['bytes_out'];

                if (!empty($c['connected_at'])) {
                    if ($parsed = $this->parseConnectedAt($c['connected_at'])) {
                        $row->connected_at = $parsed;
                    }
                }

                if (Schema::hasColumn('vpn_user_connections', 'protocol')) {
                    $row->protocol = $proto;
                }

                $row->save();

                // Update user summary
                $userUpdate = [
                    'is_online' => true,
                    'last_ip'   => $row->client_ip,
                ];
                if (Schema::hasColumn('vpn_users', 'last_protocol')) {
                    $userUpdate['last_protocol'] = $proto;
                }

                VpnUser::whereKey($uid)->update($userUpdate);
            }

            // Disconnect vanished users for this server
            $toDisconnect = VpnUserConnection::query()
                ->where('vpn_server_id', $server->id)
                ->where('is_connected', true)
                ->when(!empty($stillConnectedUserIds), fn ($q) => $q->whereNotIn('vpn_user_id', $stillConnectedUserIds))
                ->get();

            foreach ($toDisconnect as $row) {
                $row->update([
                    'is_connected'     => false,
                    'disconnected_at'  => $now,
                    'session_duration' => $row->connected_at
                        ? $now->diffInSeconds($row->connected_at)
                        : null,
                ]);

                VpnUserConnection::updateUserOnlineStatusIfNoActiveConnections($row->vpn_user_id);
            }

            // Persist live known count
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

        // Enrich & broadcast for dashboard
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
            'clients'   => count($enriched), // known/panel users only
            'users'     => $enriched,
        ]);
    }

    // ───────────────────────────── helpers ─────────────────────────────

    /** Accepts any incoming shape and returns canonical array of users incl. proto. */
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

        // Unique by (username, proto)
        $seen = [];
        return array_values(array_filter($out, function ($r) use (&$seen) {
            $name  = $r['username'] ?? null;
            $proto = strtolower($r['proto'] ?? 'openvpn');
            if (!$name) return false;
            $key = $proto . '|' . $name;
            if (isset($seen[$key])) return false;
            $seen[$key] = true;
            return true;
        }));
    }

    /** Normalise one user item from various shapes/keys into canonical keys. */
    private function normaliseUserItem($u): array
    {
        if (is_string($u)) {
            $u = ['username' => $u];
        }

        $u = (array) $u;

        // Protocol hint (default openvpn)
        $proto = strtolower($u['proto'] ?? $u['protocol'] ?? 'openvpn');

        // For WireGuard, allow public_key field to be treated as "username" identifier
        $username = $u['username']
            ?? $u['cn']
            ?? $u['CommonName']
            ?? ($proto === 'wireguard' ? ($u['public_key'] ?? $u['pubkey'] ?? 'unknown') : 'unknown');

        // Real Address may be "IP:PORT"
        $clientIp = $u['client_ip']
            ?? $u['RealAddress']
            ?? $u['real_ip']
            ?? null;

        if (is_string($clientIp) && str_contains($clientIp, ':')) {
            $clientIp = explode(':', $clientIp, 2)[0];
        }

        // Virtual address (strip /mask)
        $virt = $u['virtual_ip']
            ?? $u['VirtualAddress']
            ?? $u['virtual_address']
            ?? null;

        if (is_string($virt) && str_contains($virt, '/')) {
            $virt = explode('/', $virt, 2)[0];
        }

        // connected_at candidates
        $connectedAt = $u['connected_at']
            ?? $u['ConnectedSince']
            ?? $u['connected_since']
            ?? $u['Connected Since (time_t)']
            ?? $u['connected_seconds']
            ?? null;

        // Bytes
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

        return [
            'username'     => (string) $username,
            'public_key'   => $u['public_key'] ?? $u['pubkey'] ?? null,
            'client_ip'    => $clientIp ?: null,
            'virtual_ip'   => $virt ?: null,
            'connected_at' => $this->connectedAtToIso($connectedAt),
            'bytes_in'     => $bytesIn,
            'bytes_out'    => $bytesOut,
            'proto'        => $proto,
        ];
    }

    /** Return ISO8601 string for assorted timestamp inputs. */
    private function connectedAtToIso($value): ?string
    {
        if ($value === null || $value === '') return null;

        try {
            if (is_numeric($value)) {
                $n = (int) $value;

                // ms since epoch
                if ($n > 2_000_000_000_000) {
                    return Carbon::createFromTimestampMs($n)->toIso8601String();
                }

                // Small "seconds ago" style value
                if ($n < 315_576_000 && $n < (time() - 946_684_800)) {
                    return now()->subSeconds($n)->toIso8601String();
                }

                return Carbon::createFromTimestamp($n)->toIso8601String();
            }

            return Carbon::parse((string) $value)->toIso8601String();
        } catch (\Throwable) {
            return null;
        }
    }

    /** Parse to Carbon (or null) for DB. */
    private function parseConnectedAt($value): ?Carbon
    {
        if ($value === null || $value === '') return null;

        try {
            if (is_numeric($value)) {
                $n = (int) $value;

                if ($n > 2_000_000_000_000) {
                    return Carbon::createFromTimestampMs($n);
                }

                if ($n < 315_576_000 && $n < (time() - 946_684_800)) {
                    return now()->subSeconds($n);
                }

                return Carbon::createFromTimestamp($n);
            }

            return Carbon::parse((string) $value);
        } catch (\Throwable) {
            return null;
        }
    }

    /** Build rich list from DB so the dashboard gets complete info instantly. */
    private function enrichFromDb(VpnServer $server): array
    {
        $rows = VpnUserConnection::query()
            ->with('vpnUser:id,username')
            ->where('vpn_server_id', $server->id)
            ->where('is_connected', true)
            ->get();

        $hasProtocol = Schema::hasColumn('vpn_user_connections', 'protocol');

        return $rows->map(function (VpnUserConnection $r) use ($server, $hasProtocol) {
            return [
                'connection_id' => $r->id,
                'username'      => optional($r->vpnUser)->username ?? 'unknown',
                'client_ip'     => $r->client_ip,
                'virtual_ip'    => $r->virtual_ip,
                'connected_at'  => optional($r->connected_at)?->toIso8601String(),
                'bytes_in'      => (int) $r->bytes_received,
                'bytes_out'     => (int) $r->bytes_sent,
                'server_name'   => $server->name,
                'protocol'      => $hasProtocol ? $r->protocol : null,
            ];
        })->values()->all();
    }
}