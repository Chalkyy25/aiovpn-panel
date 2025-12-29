<?php

namespace App\Http\Controllers\Api;

use App\Events\ServerMgmtEvent;
use App\Http\Controllers\Controller;
use App\Models\VpnConnection;
use App\Models\VpnServer;
use App\Models\WireguardPeer;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WireGuardEventController extends Controller
{
    /**
     * WireGuard peers can be "connected" but idle (no handshake for a while).
     * Use 180–300s unless you enforce PersistentKeepalive on clients.
     */
    private const STALE_SECONDS = 240; // ✅ 4 minutes (recommended)

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

            // pubkey -> vpn_user_id
            $uidByPub = $publicKeys
                ? WireguardPeer::query()
                    ->where('vpn_server_id', $server->id)
                    ->where('revoked', false)
                    ->whereIn('public_key', $publicKeys)
                    ->pluck('vpn_user_id', 'public_key')
                    ->all()
                : [];

            $touched = [];

            foreach ($peers as $p) {
                if (!is_array($p)) continue;

                $pub = $p['public_key'] ?? null;
                if (!$pub) continue;

                $uid = $uidByPub[$pub] ?? null;
                if (!$uid) continue;

                $sessionKey = "wg:{$server->id}:{$pub}";
                $touched[] = $sessionKey;

                // ✅ seenAt derived from handshake epoch (or explicit seen_at if provided)
                $seenAt = $this->extractSeenAt($p);
                $isOnline = $this->isOnline($seenAt, $now);

                $row = VpnConnection::firstOrNew([
                    'vpn_server_id' => $server->id,
                    'session_key'   => $sessionKey,
                ]);

                $wasOnline = (bool) $row->is_active;

                $bytesIn  = (int)($p['bytes_received'] ?? $p['bytes_in'] ?? 0);
                $bytesOut = (int)($p['bytes_sent']     ?? $p['bytes_out'] ?? 0);

                // ✅ if we don't have a seenAt (no handshake), treat as offline and don't clobber last_seen_at
                $lastSeen = $seenAt ?: $row->last_seen_at;

                $row->fill([
                    'vpn_user_id'     => (int) $uid,
                    'protocol'        => 'WIREGUARD',
                    'wg_public_key'   => $pub,

                    'client_ip'       => $p['client_ip']  ?? $row->client_ip,
                    'virtual_ip'      => $p['virtual_ip'] ?? $row->virtual_ip,
                    'endpoint'        => $p['endpoint']   ?? $row->endpoint,

                    'bytes_in'        => $bytesIn,
                    'bytes_out'       => $bytesOut,

                    'last_seen_at'    => $lastSeen,
                    'is_active'       => $isOnline ? 1 : 0,
                    'disconnected_at' => $isOnline ? null : ($wasOnline ? $now : $row->disconnected_at),
                ]);

                // ✅ only set connected_at when we transition offline -> online (or first create)
                if ($isOnline && (!$row->exists || !$row->connected_at || !$wasOnline)) {
                    $row->connected_at = $now;
                }

                $row->save();

                // Optional peer stats mirror
                WireguardPeer::query()
                    ->where('vpn_server_id', $server->id)
                    ->where('public_key', $pub)
                    ->update([
                        'last_handshake_at' => $seenAt,
                        'transfer_rx_bytes' => $bytesIn,
                        'transfer_tx_bytes' => $bytesOut,
                    ]);
            }

            // ✅ Mark any DB sessions not present in this snapshot as offline (with grace)
            $this->disconnectMissingOrStale($server->id, $touched, $now);

            // Optional server aggregates
            $live = VpnConnection::query()
                ->where('vpn_server_id', $server->id)
                ->where('protocol', 'WIREGUARD')
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
            'peers'     => count($snapshot),
            'users'     => $snapshot,
        ]);
    }

    private function disconnectMissingOrStale(int $serverId, array $touchedSessionKeys, Carbon $now): void
    {
        $cutoff = $now->copy()->subSeconds(self::STALE_SECONDS);

        $q = VpnConnection::query()
            ->where('vpn_server_id', $serverId)
            ->where('protocol', 'WIREGUARD')
            ->where('is_active', 1)
            ->where(function ($qq) use ($touchedSessionKeys, $cutoff) {
                // ✅ explicit grouping:
                // offline if (missing from snapshot) OR (no last_seen) OR (last_seen too old)
                if (!empty($touchedSessionKeys)) {
                    $qq->whereNotIn('session_key', $touchedSessionKeys)
                       ->orWhereNull('last_seen_at')
                       ->orWhere('last_seen_at', '<', $cutoff);
                } else {
                    $qq->whereNull('last_seen_at')
                       ->orWhere('last_seen_at', '<', $cutoff);
                }
            });

        $q->update([
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
            ->map(fn ($r) => [
                'connection_id' => $r->id,
                'username'      => optional($r->vpnUser)->username ?? 'unknown',
                'client_ip'     => $r->client_ip,
                'virtual_ip'    => $r->virtual_ip,
                'connected_at'  => optional($r->connected_at)?->toIso8601String(),
                'seen_at'       => optional($r->last_seen_at)?->toIso8601String(),
                'bytes_in'      => (int) $r->bytes_in,
                'bytes_out'     => (int) $r->bytes_out,
                'server_name'   => $server->name,
                'protocol'      => 'WIREGUARD',
                'session_key'   => $r->session_key,
                'public_key'    => $r->wg_public_key,
                'is_active'     => (bool) $r->is_active,
            ])
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
        // if agent ever sends seen_at explicitly, respect it
        $seen = $peer['seen_at'] ?? $peer['seenAt'] ?? null;
        if ($seen !== null && $seen !== '') return $this->parseTime($seen);

        $raw = $peer['handshake'] ?? $peer['latest_handshake'] ?? $peer['latestHandshake'] ?? null;
        if ($raw === null || $raw === '') return null;

        $n = (int) $raw;
        if ($n <= 0) return null;

        // ns/ms tolerance
        if ($n >= 1_000_000_000_000_000) $n = (int) floor($n / 1_000_000_000);
        elseif ($n >= 1_000_000_000_000) $n = (int) floor($n / 1_000);

        return Carbon::createFromTimestamp($n);
    }

    private function parseTime(mixed $value): ?Carbon
    {
        if ($value === null) return null;
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