<?php

namespace App\Http\Controllers\Api;

use App\Events\ServerMgmtEvent;
use App\Http\Controllers\Controller;
use App\Models\VpnConnection;
use App\Models\VpnServer;
use App\Models\VpnUser;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DeployEventController extends Controller
{
    /**
     * OpenVPN sessions can momentarily disappear between polls.
     * Keep this comfortably above your poll interval to avoid flapping.
     */
    private const OFFLINE_GRACE_SECONDS = 45;

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

        Log::channel('vpn')->debug('OVPN RAW PAYLOAD', [
            'server_id' => $server->id,
            'data'      => $data,
        ]);

        if (strtolower($data['status']) !== 'mgmt') {
            return response()->json(['ok' => true]);
        }

        $ts  = $data['ts'] ?? now()->toIso8601String();
        $raw = $data['message'] ?? 'mgmt';
        $now = now();

        $incoming = $this->normalizeIncoming($data);

        Log::channel('vpn')->debug('OVPN MGMT EVENT', [
            'server_id'   => $server->id,
            'ts'          => $ts,
            'incoming'    => count($incoming),
            'user_sample' => array_slice(array_column($incoming, 'username'), 0, 50),
            'first'       => $incoming[0] ?? null,
        ]);

        DB::transaction(function () use ($server, $incoming, $now) {

            // Map username => vpn_user_id
            $names = array_values(array_unique(array_filter(array_column($incoming, 'username'))));

            $idByName = $names
                ? VpnUser::query()
                    ->whereIn('username', $names)
                    ->pluck('id', 'username')
                    ->all()
                : [];

            $touchedSessionKeys = [];

            foreach ($incoming as $c) {
                $username = trim((string)($c['username'] ?? ''));
                if ($username === '' || !isset($idByName[$username])) {
                    if ($username !== '') {
                        Log::channel('vpn')->notice('OVPN MGMT: unknown username', [
                            'server_id' => $server->id,
                            'username'  => $username,
                        ]);
                    }
                    continue;
                }

                $mgmtPort = array_key_exists('mgmt_port', $c) && $c['mgmt_port']
                    ? (int)$c['mgmt_port']
                    : 7505;

                // ✅ Stable per-session discriminator:
                // Prefer virtual_ip (best), else fall back to client_ip, else "novip"
                $vip = trim((string)($c['virtual_ip'] ?? ''));
                $cip = trim((string)($c['client_ip'] ?? ''));

                $stableSessionId = $vip !== '' ? $vip : ($cip !== '' ? $cip : 'novip');

                // ✅ Correct stable session key (NO client_id)
                $sessionKey = "ovpn:{$server->id}:{$mgmtPort}:{$username}:{$stableSessionId}";

                $touchedSessionKeys[] = $sessionKey;

                $row = VpnConnection::firstOrNew([
                    'vpn_server_id' => $server->id,
                    'session_key'   => $sessionKey,
                ]);

                $connectedAt = !empty($c['connected_at']) ? $this->parseTime($c['connected_at']) : null;

                $row->fill([
                    'vpn_user_id'     => (int)$idByName[$username],
                    'protocol'        => 'OPENVPN',
                    'wg_public_key'   => null,

                    'client_ip'       => $cip !== '' ? $cip : $row->client_ip,
                    'virtual_ip'      => $vip !== '' ? $vip : $row->virtual_ip,

                    'endpoint'        => $c['endpoint'] ?? $row->endpoint,

                    'bytes_in'        => array_key_exists('bytes_in',  $c) ? (int)$c['bytes_in']  : (int)($row->bytes_in  ?? 0),
                    'bytes_out'       => array_key_exists('bytes_out', $c) ? (int)$c['bytes_out'] : (int)($row->bytes_out ?? 0),

                    'last_seen_at'    => $now,
                    'is_active'       => 1,
                    'disconnected_at' => null,
                ]);

                if ($connectedAt) {
                    $row->connected_at = $connectedAt;
                } elseif (!$row->exists || !$row->connected_at) {
                    $row->connected_at = $now;
                }

                $row->save();
            }

            // mark missing OpenVPN sessions inactive
            $this->disconnectMissingOpenVpn($server->id, $touchedSessionKeys, $now);

            $live = VpnConnection::query()
                ->where('vpn_server_id', $server->id)
                ->where('protocol', 'OPENVPN')
                ->where('is_active', 1)
                ->count();

            $server->forceFill([
                'online_users' => $live,
                'last_sync_at' => $now,
                'last_mgmt_at' => $now,
            ])->saveQuietly();
        });

        $snapshot = $this->snapshot($server);

        event(new ServerMgmtEvent(
            $server->id,
            $ts,
            $snapshot,
            implode(',', array_column($snapshot, 'username')),
            $raw
        ));

        return response()->json([
            'ok'        => true,
            'server_id' => $server->id,
            'clients'   => count($snapshot),
            'users'     => $snapshot,
        ]);
    }

    private function disconnectMissingOpenVpn(int $serverId, array $touchedSessionKeys, Carbon $now): void
    {
        $graceAgo = $now->copy()->subSeconds(self::OFFLINE_GRACE_SECONDS);

        VpnConnection::query()
            ->where('vpn_server_id', $serverId)
            ->where('protocol', 'OPENVPN')
            ->where('is_active', 1)
            ->when(!empty($touchedSessionKeys), fn($q) => $q->whereNotIn('session_key', $touchedSessionKeys))
            ->where(function ($q) use ($graceAgo) {
                $q->whereNull('last_seen_at')
                  ->orWhere('last_seen_at', '<', $graceAgo);
            })
            ->update([
                'is_active'       => 0,
                'disconnected_at' => $now,
            ]);
    }

    private function snapshot(VpnServer $server): array
    {
        return VpnConnection::with('vpnUser:id,username')
            ->where('vpn_server_id', $server->id)
            ->where('is_active', 1)
            ->orderByDesc('last_seen_at')
            ->limit(500)
            ->get()
            ->map(fn($r) => [
                'connection_id' => $r->id,
                'username'      => optional($r->vpnUser)->username ?? 'unknown',
                'client_ip'     => $r->client_ip,
                'virtual_ip'    => $r->virtual_ip,
                'connected_at'  => optional($r->connected_at)?->toIso8601String(),
                'seen_at'       => optional($r->last_seen_at)?->toIso8601String(),
                'bytes_in'      => (int)$r->bytes_in,
                'bytes_out'     => (int)$r->bytes_out,
                'server_name'   => $server->name,
                'protocol'      => 'OPENVPN',
                'session_key'   => $r->session_key,
                'public_key'    => null,
                'is_active'     => (bool)$r->is_active,
            ])
            ->values()
            ->all();
    }

    private function normalizeIncoming(array $data): array
    {
        $out = [];

        if (!empty($data['users']) && is_array($data['users'])) {
            foreach ($data['users'] as $u) {
                $u = is_string($u) ? ['username' => $u] : (array)$u;

                $username = (string)($u['username'] ?? $u['cn'] ?? $u['CommonName'] ?? 'unknown');

                $out[] = [
                    'username'     => $username,
                    'mgmt_port'    => array_key_exists('mgmt_port', $u) ? (int)$u['mgmt_port'] : null,
                    'client_ip'    => $this->stripPort($u['client_ip'] ?? $u['RealAddress'] ?? null),
                    'virtual_ip'   => $this->stripCidr($u['virtual_ip'] ?? $u['VirtualAddress'] ?? null),
                    'connected_at' => $u['connected_at'] ?? $u['ConnectedSince'] ?? null,
                    'bytes_in'     => (int)($u['bytes_in'] ?? $u['BytesReceived'] ?? 0),
                    'bytes_out'    => (int)($u['bytes_out'] ?? $u['BytesSent'] ?? 0),
                    'endpoint'     => $u['endpoint'] ?? null,
                ];
            }
        } elseif (!empty($data['cn_list'])) {
            foreach (explode(',', (string)$data['cn_list']) as $name) {
                $name = trim($name);
                if ($name !== '') {
                    $out[] = ['username' => $name, 'mgmt_port' => null];
                }
            }
        }

        // de-dupe by (username, mgmt_port, virtual_ip/client_ip)
        $seen = [];
        $filtered = [];

        foreach ($out as $r) {
            $name = trim((string)($r['username'] ?? ''));
            if ($name === '' || strcasecmp($name, 'unknown') === 0 || strcasecmp($name, 'UNDEF') === 0) continue;

            // loosen validation: allow your usernames; don't accidentally drop legit ones
            if (strlen($name) < 1 || strlen($name) > 128) continue;

            $mp  = array_key_exists('mgmt_port', $r) && $r['mgmt_port'] !== null ? (string)$r['mgmt_port'] : 'nomp';
            $vip = trim((string)($r['virtual_ip'] ?? ''));
            $cip = trim((string)($r['client_ip'] ?? ''));

            $sid = $vip !== '' ? $vip : ($cip !== '' ? $cip : 'novip');
            $k   = "ovpn|{$name}|{$mp}|{$sid}";

            if (isset($seen[$k])) continue;
            $seen[$k] = true;

            $filtered[] = $r;
        }

        return array_values($filtered);
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
}