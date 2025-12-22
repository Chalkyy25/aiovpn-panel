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

        $status = strtolower($data['status']);
        if ($status !== 'mgmt') {
            Log::channel('vpn')->debug("DeployEventController: ignore status='{$status}' server={$server->id}");
            return response()->json(['ok' => true]);
        }

        $ts  = $data['ts'] ?? now()->toIso8601String();
        $raw = $data['message'] ?? $status;
        $now = now();

        $incoming = $this->filterIncoming($this->normaliseIncoming($data));

        Log::channel('vpn')->debug(sprintf(
            'MGMT EVENT server=%d ts=%s incoming=%d [%s]',
            $server->id,
            $ts,
            count($incoming),
            implode(',', array_column($incoming, 'username'))
        ));

        DB::transaction(function () use ($server, $incoming, $now) {

            // Preload user maps
            [$idByName, $idByWgKey] = $this->buildUserMaps($incoming);

            $touchedSessionKeys = [];

            foreach ($incoming as $c) {
                $proto     = $this->normalizeProto($c['proto'] ?? null); // OPENVPN / WIREGUARD
                $username  = trim((string)($c['username'] ?? ''));
                $publicKey = $c['public_key'] ?? null;
                $clientId  = isset($c['client_id']) ? (int) $c['client_id'] : null;
                $mgmtPort  = isset($c['mgmt_port']) ? (int) $c['mgmt_port'] : null;

                // Resolve user id
                $uid = null;

                if ($proto === 'WIREGUARD') {
                    $key = $publicKey ?: $username; // WG snapshots often use pubkey as username
                    if ($key && isset($idByWgKey[$key])) {
                        $uid = (int) $idByWgKey[$key];
                        $publicKey = $publicKey ?: $key;
                    }
                } else {
                    if (isset($idByName[$username])) {
                        $uid = (int) $idByName[$username];
                    }
                }

                if (!$uid) {
                    Log::channel('vpn')->notice("MGMT: unknown {$proto} user='{$username}' server={$server->id}");
                    continue;
                }

                // Parse connected_at
                $connectedAt = !empty($c['connected_at'])
                    ? $this->parseConnectedAt($c['connected_at'])
                    : null;

                // Build session_key (identity)
                $sessionKey = $this->makeSessionKey(
                    $proto,
                    $username,
                    $clientId,
                    $mgmtPort,
                    $publicKey
                );

                if (!$sessionKey) {
                    Log::channel('vpn')->notice("MGMT: cannot build session_key proto={$proto} user='{$username}' server={$server->id}");
                    continue;
                }

                $touchedSessionKeys[] = $sessionKey;

                /** @var VpnUserConnection $row */
                $row = VpnUserConnection::firstOrNew([
                    'vpn_server_id' => $server->id,
                    'session_key'   => $sessionKey,
                ]);

                // Identity + session fields
                $row->vpn_user_id = $uid;
                $row->protocol    = $proto;
                $row->is_connected = true;
                $row->disconnected_at = null;

                if ($proto === 'OPENVPN') {
                    $row->client_id  = $clientId;
                    $row->mgmt_port  = $mgmtPort ?: 7505;
                    $row->public_key = null;
                } else {
                    $row->public_key = $publicKey;
                    $row->client_id  = null;
                    $row->mgmt_port  = null;
                }

                // Always refresh connected_at if provided (prevents stale oldest logic)
                if ($connectedAt) {
                    $row->connected_at = $connectedAt;
                } elseif (!$row->connected_at) {
                    $row->connected_at = $now;
                }

                // Network fields
                if (!empty($c['client_ip']))  $row->client_ip  = $c['client_ip'];
                if (!empty($c['virtual_ip'])) $row->virtual_ip = $c['virtual_ip'];

                // Counters
                if (array_key_exists('bytes_in', $c))  $row->bytes_received = (int) $c['bytes_in'];
                if (array_key_exists('bytes_out', $c)) $row->bytes_sent     = (int) $c['bytes_out'];

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

            // Mark missing sessions offline after grace
            $this->disconnectMissingSessions($server->id, $touchedSessionKeys, $now);

            // Server aggregates
            $liveKnown = VpnUserConnection::query()
                ->where('vpn_server_id', $server->id)
                ->where('is_connected', true)
                ->count();

            $update = [];
            if (Schema::hasColumn('vpn_servers', 'online_users')) $update['online_users'] = $liveKnown;
            if (Schema::hasColumn('vpn_servers', 'last_mgmt_at')) $update['last_mgmt_at'] = $now;
            if ($update) $server->forceFill($update)->saveQuietly();

            // Enforcement hook goes here AFTER state is correct
            // $this->enforceDeviceLimits($server, $now);
        });

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

    /* ========================= Core helpers ========================= */

    private function filterIncoming(array $incomingAll): array
    {
        return array_values(array_filter($incomingAll, function (array $r) {
            $name = trim((string)($r['username'] ?? ''));
            $p    = strtolower((string)($r['proto'] ?? 'openvpn'));

            if ($name === '' || strcasecmp($name, 'UNDEF') === 0 || strcasecmp($name, 'unknown') === 0) {
                return false;
            }

            if ($p === 'openvpn') {
                return (bool) preg_match('/^[A-Za-z0-9._-]{3,64}$/', $name);
            }

            if ($p === 'wireguard') {
                return (bool) preg_match('#^[A-Za-z0-9+/=]{32,80}$#', $name);
            }

            return true;
        }));
    }

    /**
     * Returns [idByName, idByWgKey]
     */
    private function buildUserMaps(array $incoming): array
    {
        $ovpnNames = [];
        $wgKeys    = [];

        foreach ($incoming as $c) {
            $proto = $this->normalizeProto($c['proto'] ?? null);

            if ($proto === 'WIREGUARD') {
                if (!empty($c['public_key'])) $wgKeys[] = $c['public_key'];
                if (!empty($c['username']))   $wgKeys[] = $c['username'];
            } else {
                if (!empty($c['username']))   $ovpnNames[] = $c['username'];
            }
        }

        $ovpnNames = array_values(array_unique($ovpnNames));
        $wgKeys    = array_values(array_unique(array_filter($wgKeys)));

        $idByName = !empty($ovpnNames)
            ? VpnUser::whereIn('username', $ovpnNames)->pluck('id', 'username')->all()
            : [];

        $idByWgKey = (!empty($wgKeys) && Schema::hasColumn('vpn_users', 'wireguard_public_key'))
            ? VpnUser::whereIn('wireguard_public_key', $wgKeys)->pluck('id', 'wireguard_public_key')->all()
            : [];

        return [$idByName, $idByWgKey];
    }

    private function makeSessionKey(string $proto, string $username, ?int $clientId, ?int $mgmtPort, ?string $publicKey): ?string
    {
        if ($proto === 'OPENVPN') {
            if ($clientId === null) return null; // no client_id = cannot identify session
            $mp = $mgmtPort ?: 7505;
            $flavour = ($mp === 7506) ? 'tcp' : 'udp';
            // ✅ unique enough: server + mgmt + username + client_id
            return "ovpn:{$mp}:{$flavour}:{$username}:{$clientId}";
        }

        if ($proto === 'WIREGUARD') {
            if (empty($publicKey)) return null;
            return "wg:{$publicKey}";
        }

        return null;
    }

    private function disconnectMissingSessions(int $serverId, array $touchedSessionKeys, Carbon $now): void
    {
        $graceAgo = $now->copy()->subSeconds(self::OFFLINE_GRACE);

        $query = VpnUserConnection::query()
            ->where('vpn_server_id', $serverId)
            ->where('is_connected', true);

        if (!empty($touchedSessionKeys)) {
            $query->whereNotIn('session_key', $touchedSessionKeys);
        }

        $toDisconnect = $query->get();

        foreach ($toDisconnect as $row) {
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

    /* ========================= Your existing helpers below ========================= */

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

    // keep your normaliseIncoming(), normaliseUserItem(), connectedAtToIso(), parseConnectedAt(), enrichFromDb() as-is
}