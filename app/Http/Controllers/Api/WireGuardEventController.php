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
    /**
     * A peer is considered "online" if its last handshake is within this window.
     * Keep this >= your poll interval * 2 to avoid flapping.
     */
    private const STALE_SECONDS = 180;

    public function store(Request $request, VpnServer $server): JsonResponse
    {
        $data = $request->validate([
            'status'  => 'required|string',
            'ts'      => 'nullable|string',
            'message' => 'nullable|string',
            'peers'   => 'nullable|array',
        ]);

        if (strtolower($data['status']) !== 'mgmt') {
            return response()->json(['ok' => true]);
        }

        $ts    = $data['ts'] ?? now()->toIso8601String();
        $raw   = $data['message'] ?? 'wg-mgmt';
        $now   = now();
        $peers = is_array($data['peers'] ?? null) ? $data['peers'] : [];

        // Extract pubkeys from payload
        $publicKeys = array_values(array_filter(array_map(
            fn ($p) => is_array($p) ? ($p['public_key'] ?? null) : null,
            $peers
        )));

        Log::channel('vpn')->debug('[wg-events received]', [
            'server_id'   => $server->id,
            'peer_count'  => count($peers),
            'sample_keys' => array_slice($publicKeys, 0, 10),
        ]);

        DB::transaction(function () use ($server, $peers, $publicKeys, $now) {
            // Map pubkey => vpn_user_id (SCOPED TO THIS SERVER)
            // With Option A + proper indexes, this becomes deterministic.
            $uidByPubKey = $publicKeys
                ? WireguardPeer::query()
                    ->where('vpn_server_id', $server->id)
                    ->where('revoked', false)
                    ->whereIn('public_key', $publicKeys)
                    ->get(['public_key', 'vpn_user_id'])
                    ->pluck('vpn_user_id', 'public_key')
                    ->all()
                : [];

            $touchedSessionKeys = [];
            $touchedUserIds = [];

            foreach ($peers as $p) {
                $pub = $p['public_key'] ?? null;
                if (!$pub) continue;

                $uid = $uidByPubKey[$pub] ?? null;
                if (!$uid) {
                    Log::channel('vpn')->notice("WG: unknown peer pubkey={$pub} server={$server->id}");
                    continue;
                }

                // ✅ CONCRETE FIX:
                // session_key must include server_id so keys can never collide across servers
                $sessionKey = "wg:{$server->id}:{$pub}";
                $touchedSessionKeys[] = $sessionKey;
                $touchedUserIds[(int)$uid] = true;

                $seenAt = $this->extractSeenAt($p);
                $isOnline = $this->isOnline($seenAt, $now);

                $row = VpnUserConnection::firstOrNew([
                    'vpn_server_id' => $server->id,
                    'session_key'   => $sessionKey,
                ]);

                $wasOnline = (bool) $row->is_connected;

                $bytesIn  = (int) ($p['bytes_received'] ?? ($p['bytes_in'] ?? 0));
                $bytesOut = (int) ($p['bytes_sent']     ?? ($p['bytes_out'] ?? 0));

                $row->fill([
                    'vpn_user_id'     => (int) $uid,
                    'protocol'        => 'WIREGUARD',
                    'public_key'      => $pub,

                    'client_ip'       => $p['client_ip']  ?? $row->client_ip,
                    'virtual_ip'      => $p['virtual_ip'] ?? $row->virtual_ip,

                    'bytes_received'  => $bytesIn,
                    'bytes_sent'      => $bytesOut,

                    'seen_at'         => $seenAt,
                    'is_connected'    => $isOnline,

                    // WG has no explicit disconnect event; we only set disconnected_at when we mark stale/offline.
                    'disconnected_at' => $isOnline ? null : ($row->disconnected_at ?? null),
                ]);

                // connected_at rules:
                // - if it becomes online from offline OR connected_at missing => set connected_at now
                if ($isOnline && (!$wasOnline || !$row->connected_at)) {
                    $row->connected_at = $now;
                }

                // if it just went offline now, stamp disconnected_at + duration
                if (!$isOnline && $wasOnline) {
                    $row->disconnected_at = $now;
                    $row->session_duration = $row->connected_at ? $now->diffInSeconds($row->connected_at) : null;
                }

                $row->save();

                // Update authoritative peer stats for this server
                WireguardPeer::query()
                    ->where('vpn_server_id', $server->id)
                    ->where('public_key', $pub)
                    ->update([
                        'last_handshake_at' => $seenAt,
                        'transfer_rx_bytes' => $bytesIn,
                        'transfer_tx_bytes' => $bytesOut,
                    ]);

                // Update vpn_users "is_online" (only set true when online; offline handled via central method below)
                if ($isOnline) {
                    $userUpdate = ['is_online' => true, 'last_ip' => $row->client_ip];
                    if (Schema::hasColumn('vpn_users', 'last_protocol')) {
                        $userUpdate['last_protocol'] = 'WIREGUARD';
                    }
                    VpnUser::whereKey((int)$uid)->update($userUpdate);
                }
            }

            // ✅ Critical: mark WG sessions OFFLINE that are missing from snapshot or stale
            $this->disconnectMissingOrStale($server->id, $touchedSessionKeys, $now);

            // Best-effort: recompute user online flags safely after updates
            foreach (array_keys($touchedUserIds) as $uid) {
                VpnUserConnection::updateUserOnlineStatusIfNoActiveConnections((int) $uid);
            }

            // Server aggregates (WG only)
            $wgLive = VpnUserConnection::query()
                ->where('vpn_server_id', $server->id)
                ->where('protocol', 'WIREGUARD')
                ->where('is_connected', true)
                ->count();

            $serverUpdate = [];
            if (Schema::hasColumn('vpn_servers', 'last_sync_at')) $serverUpdate['last_sync_at'] = $now;

            // IMPORTANT:
            // If vpn_servers.online_users is meant to be "OpenVPN only" elsewhere, do NOT overwrite it here.
            // If you want combined totals, create a separate column like wg_online_users or total_online_users.
            if (Schema::hasColumn('vpn_servers', 'wg_online_users')) {
                $serverUpdate['wg_online_users'] = $wgLive;
            }

            if ($serverUpdate) $server->forceFill($serverUpdate)->saveQuietly();
        });

        // Broadcast combined snapshot from DB (your dashboard can filter by protocol)
        $enriched = $this->enrich($server);
        
        Log::channel('vpn')->debug('[wg-events broadcast sample]', [
            'server_id' => $server->id,
            'count'     => count($enriched),
            'top'       => $enriched[0] ?? null,
        ]);
        
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

    private function disconnectMissingOrStale(int $serverId, array $touchedSessionKeys, Carbon $now): void
    {
        $cutoff = $now->copy()->subSeconds(self::STALE_SECONDS);

        $q = VpnUserConnection::query()
            ->where('vpn_server_id', $serverId)
            ->where('protocol', 'WIREGUARD')
            ->where('is_connected', true)
            ->where(function ($qq) use ($touchedSessionKeys, $cutoff) {
                if (!empty($touchedSessionKeys)) {
                    $qq->whereNotIn('session_key', $touchedSessionKeys);
                }
                $qq->orWhereNull('seen_at')
                   ->orWhere('seen_at', '<', $cutoff);
            });

        foreach ($q->get() as $row) {
            $row->update([
                'is_connected'     => false,
                'disconnected_at'  => $now,
                'session_duration' => $row->connected_at ? $now->diffInSeconds($row->connected_at) : null,
            ]);

            VpnUserConnection::updateUserOnlineStatusIfNoActiveConnections((int) $row->vpn_user_id);
        }
    }

    private function enrich(VpnServer $server): array
{
    $cutoff = now()->subSeconds(self::STALE_SECONDS);

    // authoritative peers on this server only
    $peers = WireguardPeer::with('vpnUser:id,username')
        ->where('vpn_server_id', $server->id)
        ->where('revoked', false)
        ->orderByDesc('id')
        ->get(['id','vpn_user_id','public_key','ip_address','last_handshake_at','transfer_rx_bytes','transfer_tx_bytes'])
        ->unique('public_key')
        ->values();

    $connByPub = VpnUserConnection::query()
        ->where('vpn_server_id', $server->id)
        ->where('protocol', 'WIREGUARD')
        ->get(['id','public_key','session_key','client_ip','virtual_ip','connected_at','seen_at','bytes_received','bytes_sent','is_connected'])
        ->keyBy(fn ($r) => (string) $r->public_key);

    $rows = $peers->map(function ($p) use ($server, $connByPub, $cutoff) {
        $conn = $connByPub[(string) $p->public_key] ?? null;

        $seenAt = $conn?->seen_at ?? $p->last_handshake_at;
        $isOnline = $seenAt ? $seenAt->gte($cutoff) : false;

        return [
            'connection_id' => $conn?->id,
            'username'      => optional($p->vpnUser)->username ?? 'unknown',
            'client_ip'     => $conn?->client_ip,
            'virtual_ip'    => $conn?->virtual_ip ?? $p->ip_address,
            'connected_at'  => optional($conn?->connected_at)?->toIso8601String(),
            'seen_at'       => optional($seenAt)?->toIso8601String(),
            'bytes_in'      => (int) ($conn?->bytes_received ?? $p->transfer_rx_bytes),
            'bytes_out'     => (int) ($conn?->bytes_sent ?? $p->transfer_tx_bytes),
            'server_name'   => $server->name,
            'protocol'      => 'WIREGUARD',
            'session_key'   => "wg:{$server->id}:{$p->public_key}",
            'public_key'    => $p->public_key,
            'is_connected'  => $isOnline,
        ];
    })->values();

    // ✅ Sort so enriched[0] is always the most relevant peer
    return $rows
        ->sortByDesc(fn ($r) => (int) ($r['is_connected'] ?? 0))
        ->sortByDesc(fn ($r) => $r['seen_at'] ?? '')
        ->values()
        ->all();
}

    private function isOnline(?Carbon $seenAt, Carbon $now): bool
    {
        if (!$seenAt) return false;
        return $seenAt->gte($now->copy()->subSeconds(self::STALE_SECONDS));
    }

    private function extractSeenAt(array $peer): ?Carbon
    {
        $seen = $peer['seen_at'] ?? $peer['seenAt'] ?? null;
        if ($seen !== null && $seen !== '') {
            return $this->parseTime($seen);
        }

        $raw = $peer['handshake'] ?? $peer['latest_handshake'] ?? $peer['latestHandshake'] ?? null;
        if ($raw === null || $raw === '') return null;

        $n = (int) $raw;
        if ($n <= 0) return null;

        // tolerate ns/ms
        if ($n >= 1_000_000_000_000_000) {
            $n = (int) floor($n / 1_000_000_000);
        } elseif ($n >= 1_000_000_000_000) {
            $n = (int) floor($n / 1_000);
        }

        return Carbon::createFromTimestamp($n);
    }

    private function parseTime(mixed $value): ?Carbon
    {
        if ($value === null) return null;

        if (is_int($value) || is_float($value)) {
            return $this->extractSeenAt(['handshake' => (int) $value]);
        }

        $s = trim((string) $value);
        if ($s === '') return null;

        if (ctype_digit($s)) {
            return $this->extractSeenAt(['handshake' => (int) $s]);
        }

        try {
            return Carbon::parse($s);
        } catch (\Throwable) {
            return null;
        }
    }
}