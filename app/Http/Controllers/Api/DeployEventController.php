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
 * Handles real-time connection events from OpenVPN servers.
 * For WireGuard events, see WireGuardEventController.
 */
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

            [$idByOvpnName] = $this->buildUserMaps($incoming);

            $touchedKeys = [];

            foreach ($incoming as $c) {
                $username = trim((string)($c['username'] ?? ''));
                $clientId = isset($c['client_id']) ? (int)$c['client_id'] : null;
                $mgmtPort = isset($c['mgmt_port']) ? (int)$c['mgmt_port'] : null;

                // Resolve user id
                if (!$username || !isset($idByOvpnName[$username])) {
                    Log::channel('vpn')->notice("MGMT: unknown OPENVPN user='{$username}' server={$server->id}");
                    continue;
                }

                $uid = (int) $idByOvpnName[$username];

                // Build session key
                $sessionKey = $this->sessionKey($username, $clientId, $mgmtPort);
                if (!$sessionKey) {
                    Log::channel('vpn')->notice("MGMT: missing session identity user='{$username}' server={$server->id}");
                    continue;
                }

                $touchedKeys[] = $sessionKey;

                // Upsert connection record
                $row = VpnUserConnection::firstOrNew([
                    'vpn_server_id' => $server->id,
                    'session_key'   => $sessionKey,
                ]);
                
                $isNew = !$row->exists;
                
                // Parse incoming connected_at
                $incomingConnectedAt = !empty($c['connected_at']) 
                    ? $this->parseTime($c['connected_at']) 
                    : null;
                
                // Fill fields
                $row->fill([
                    'vpn_user_id'     => $uid,
                    'protocol'        => 'OPENVPN',
                    'client_id'       => $clientId,
                    'mgmt_port'       => $mgmtPort ?: 7505,
                    'is_connected'    => true,
                    'disconnected_at' => null,
                    'client_ip'       => $c['client_ip'] ?? $row->client_ip,
                    'virtual_ip'      => $c['virtual_ip'] ?? $row->virtual_ip,
                    'bytes_received'  => array_key_exists('bytes_in', $c) ? (int)$c['bytes_in'] : (int)($row->bytes_received ?? 0),
                    'bytes_sent'      => array_key_exists('bytes_out', $c) ? (int)$c['bytes_out'] : (int)($row->bytes_sent ?? 0),
                    'seen_at'         => $now,
                ]);
                
                // Set connected_at
                if ($incomingConnectedAt) {
                    $row->connected_at = $incomingConnectedAt;
                } elseif ($isNew || !$row->connected_at) {
                    $row->connected_at = $now;
                }
                
                $row->save();
            
                // Update user status
                $userUpdate = [
                    'is_online' => true,
                    'last_ip'   => $row->client_ip,
                ];
                if (Schema::hasColumn('vpn_users', 'last_protocol')) {
                    $userUpdate['last_protocol'] = 'OPENVPN';
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
            if (Schema::hasColumn('vpn_servers', 'last_sync_at')) $serverUpdate['last_sync_at'] = $now;
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

                $username = (string)($u['username'] ?? $u['cn'] ?? $u['CommonName'] ?? 'unknown');

                $out[] = [
                    'proto'        => 'openvpn',
                    'username'     => $username,
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

        // dedupe and validate
        $seen = [];
        return array_values(array_filter($out, function ($r) use (&$seen) {
            $name = trim((string)($r['username'] ?? ''));

            if ($name === '' || strcasecmp($name, 'unknown') === 0 || strcasecmp($name, 'UNDEF') === 0) {
                return false;
            }

            if (!preg_match('/^[A-Za-z0-9._-]{3,64}$/', $name)) return false;
            
            $k = "ovpn|{$name}|" . ($r['client_id'] ?? 'nocid');

            if (isset($seen[$k])) return false;
            $seen[$k] = true;
            return true;
        }));
    }

    private function buildUserMaps(array $incoming): array
    {
        $ovpnNames = array_values(array_unique(array_filter(
            array_column($incoming, 'username')
        )));

        $idByOvpnName = $ovpnNames
            ? VpnUser::whereIn('username', $ovpnNames)->pluck('id', 'username')->all()
            : [];

        return [$idByOvpnName];
    }

    private function sessionKey(string $username, ?int $clientId, ?int $mgmtPort): ?string
    {
        // OpenVPN needs a client_id (from status parser)
        if ($clientId === null) return null;
        $mp = $mgmtPort ?: 7505;
        return "ovpn:{$mp}:{$clientId}:{$username}";
    }

    private function disconnectMissing(int $serverId, array $touchedSessionKeys, Carbon $now): void
    {
        $graceAgo = $now->copy()->subSeconds(self::OFFLINE_GRACE);

        // Disconnect OpenVPN sessions not in current payload (with grace period)
        $q = VpnUserConnection::where('vpn_server_id', $serverId)
            ->where('protocol', 'OPENVPN')
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
        $hasSeenAt = Schema::hasColumn('vpn_user_connections', 'seen_at');

        return VpnUserConnection::with('vpnUser:id,username')
            ->where('vpn_server_id', $server->id)
            ->where('protocol', 'OPENVPN')
            ->where('is_connected', true)
            ->get()
            ->map(fn ($r) => [
                'connection_id' => $r->id,
                'username'      => optional($r->vpnUser)->username ?? 'unknown',
                'client_ip'     => $r->client_ip,
                'virtual_ip'    => $r->virtual_ip,
                'connected_at'  => optional($r->connected_at)?->toIso8601String(),
                'seen_at'       => $hasSeenAt 
                    ? optional($r->seen_at)?->toIso8601String()
                    : optional($r->updated_at)?->toIso8601String(),
                'bytes_in'      => (int)$r->bytes_received,
                'bytes_out'     => (int)$r->bytes_sent,
                'server_name'   => $server->name,
                'protocol'      => 'OPENVPN',
                'session_key'   => $r->session_key,
                'client_id'     => $r->client_id,
                'mgmt_port'     => $r->mgmt_port,
            ])->values()->all();
    }
}