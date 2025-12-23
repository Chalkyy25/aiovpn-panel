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

class WireGuardEventController extends Controller
{
    // If a peer hasn't been seen in this window, mark offline
    private const STALE_SECONDS = 180; // 3 minutes

    public function store(Request $request, VpnServer $server): JsonResponse
    {
        $data = $request->validate([
            'status'  => 'required|string',
            'message' => 'nullable|string',
            'ts'      => 'nullable|string',
            'peers'   => 'nullable|array',
        ]);

        if (strtolower($data['status']) !== 'mgmt') {
            return response()->json(['ok' => true]);
        }

        $ts    = $data['ts'] ?? now()->toIso8601String();
        $raw   = $data['message'] ?? 'wg-mgmt';
        $now   = now();
        $peers = is_array($data['peers'] ?? null) ? $data['peers'] : [];

        Log::channel('vpn')->debug(sprintf(
            'WG MGMT EVENT server=%d ts=%s peers=%d',
            $server->id,
            $ts,
            count($peers)
        ));

        DB::transaction(function () use ($server, $peers, $now) {
            // Build pubkey -> vpn_user_id map in one query
            $publicKeys = array_values(array_unique(array_filter(array_map(
                fn ($p) => is_array($p) ? ($p['public_key'] ?? null) : null,
                $peers
            ))));

            $uidByPubKey = $publicKeys
                ? WireguardPeer::whereIn('public_key', $publicKeys)->pluck('vpn_user_id', 'public_key')->all()
                : [];

            $touchedSessionKeys = [];

            foreach ($peers as $p) {
                if (!is_array($p)) continue;

                $publicKey = $p['public_key'] ?? null;
                if (!$publicKey) continue;

                $uid = $uidByPubKey[$publicKey] ?? null;
                if (!$uid) {
                    Log::channel('vpn')->notice("WG: unknown peer public_key={$publicKey} server={$server->id}");
                    continue;
                }

                $sessionKey = "wg:{$publicKey}";
                $touchedSessionKeys[] = $sessionKey;

                // Load existing row if any
                $row = VpnUserConnection::firstOrNew([
                    'vpn_server_id' => $server->id,
                    'session_key'   => $sessionKey,
                ]);

                // Capture previous state BEFORE we overwrite anything
                $isNew          = !$row->exists;
                $prevSeen       = $row->seen_at;
                $prevWasOffline = $row->exists && (
                    !$row->is_connected || !is_null($row->disconnected_at)
                );

                // seen_at: prefer payload -> fallback now
                $seenAt = !empty($p['seen_at'])
                    ? $this->parseTime($p['seen_at'])
                    : $now;

                // optional hygiene on IPs
                $clientIp  = $this->stripPort($p['client_ip'] ?? null) ?? $row->client_ip;
                $virtualIp = $this->stripCidr($p['virtual_ip'] ?? null) ?? $row->virtual_ip;

                // bytes: keep monotonic-ish
                $bytesIn  = (int)($p['bytes_in']  ?? $p['bytes_received'] ?? 0);
                $bytesOut = (int)($p['bytes_out'] ?? $p['bytes_sent']     ?? 0);

                // Apply new state
                $row->fill([
                    'vpn_user_id'     => $uid,
                    'protocol'        => 'WIREGUARD',
                    'public_key'      => $publicKey,

                    'is_connected'    => true,
                    'disconnected_at' => null,

                    'client_ip'       => $clientIp,
                    'virtual_ip'      => $virtualIp,

                    'bytes_received'  => $bytesIn,
                    'bytes_sent'      => $bytesOut,

                    'seen_at'         => $seenAt,
                ]);

                // Decide if this should start a NEW session (connected_at reset)
                $wasStale = $prevSeen && $prevSeen->lt($now->copy()->subSeconds(self::STALE_SECONDS));

                if ($isNew || $prevWasOffline || $wasStale || !$row->connected_at) {
                    $row->connected_at = $now;
                }

                $row->save();

                // Update user summary
                $userUpdate = [
                    'is_online' => true,
                    'last_ip'   => $row->client_ip,
                ];
                if (Schema::hasColumn('vpn_users', 'last_protocol')) {
                    $userUpdate['last_protocol'] = 'WIREGUARD';
                }
                VpnUser::whereKey($uid)->update($userUpdate);
            }

            // Mark sessions offline if missing from payload OR too old
            $this->disconnectStale($server->id, $touchedSessionKeys, $now);

            // Server aggregates
            $liveCount = VpnUserConnection::where('vpn_server_id', $server->id)
                ->where('protocol', 'WIREGUARD')
                ->where('is_connected', true)
                ->count();

            $serverUpdate = [];
            if (Schema::hasColumn('vpn_servers', 'online_users')) $serverUpdate['online_users'] = $liveCount;
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
            'peers'     => count($enriched),
            'users'     => $enriched,
        ]);
    }

    private function disconnectStale(int $serverId, array $touchedSessionKeys, Carbon $now): void
    {
        $cutoff = $now->copy()->subSeconds(self::STALE_SECONDS);

        $q = VpnUserConnection::where('vpn_server_id', $serverId)
            ->where('protocol', 'WIREGUARD')
            ->where('is_connected', true)
            ->where(function ($qq) use ($touchedSessionKeys, $cutoff) {
                // missing from payload
                if (!empty($touchedSessionKeys)) {
                    $qq->whereNotIn('session_key', $touchedSessionKeys);
                }

                // OR stale seen_at
                $qq->orWhereNull('seen_at')
                   ->orWhere('seen_at', '<', $cutoff);
            });

        foreach ($q->get() as $row) {
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

                // ms epoch
                if ($n > 2_000_000_000_000) return Carbon::createFromTimestampMs($n);

                // seconds epoch
                if ($n > 946_684_800) return Carbon::createFromTimestamp($n);

                // if someone sends "age seconds" (bad), treat as "now - age"
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

    private function enrich(VpnServer $server): array
    {
        $hasSeenAt = Schema::hasColumn('vpn_user_connections', 'seen_at');

        return VpnUserConnection::with('vpnUser:id,username')
            ->where('vpn_server_id', $server->id)
            ->where('protocol', 'WIREGUARD')
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
                'bytes_in'      => (int) $r->bytes_received,
                'bytes_out'     => (int) $r->bytes_sent,
                'server_name'   => $server->name,
                'protocol'      => 'WIREGUARD',
                'session_key'   => $r->session_key,
                'public_key'    => $r->public_key,
            ])->values()->all();
    }
}