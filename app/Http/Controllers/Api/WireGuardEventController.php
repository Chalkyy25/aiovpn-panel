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
    private const STALE_SECONDS = 180;

    public function store(Request $request, VpnServer $server): JsonResponse
    {
        $data = $request->validate([
            'status' => 'required|string',
            'ts'     => 'nullable|string',
            'message'=> 'nullable|string',
            'peers'  => 'nullable|array',
        ]);

        if (strtolower($data['status']) !== 'mgmt') {
            return response()->json(['ok' => true]);
        }

        $ts    = $data['ts'] ?? now()->toIso8601String();
        $raw   = $data['message'] ?? 'wg-mgmt';
        $now   = now();
        $peers = is_array($data['peers'] ?? null) ? $data['peers'] : [];

        Log::channel('vpn')->debug("WG MGMT EVENT server={$server->id} ts={$ts} peers=" . count($peers));

        DB::transaction(function () use ($server, $peers, $now) {
            $publicKeys = array_values(array_filter(array_map(
                fn ($p) => $p['public_key'] ?? null,
                $peers
            )));

            $uidByPubKey = $publicKeys
                ? WireguardPeer::whereIn('public_key', $publicKeys)->pluck('vpn_user_id', 'public_key')->all()
                : [];

            $touched = [];

            foreach ($peers as $p) {
                $pub = $p['public_key'] ?? null;
                if (!$pub) continue;

                $uid = $uidByPubKey[$pub] ?? null;
                if (!$uid) {
                    Log::channel('vpn')->notice("WG: unknown peer pubkey={$pub} server={$server->id}");
                    continue;
                }

                $sessionKey = "wg:{$pub}";
                $touched[] = $sessionKey;

                $seenAt = $this->extractSeenAt($p);

                // “Online” means handshake recent
                $isOnline = $seenAt && $seenAt->gte($now->copy()->subSeconds(self::STALE_SECONDS));

                $row = VpnUserConnection::firstOrNew([
                    'vpn_server_id' => $server->id,
                    'session_key'   => $sessionKey,
                ]);

                $wasOnline = (bool) $row->is_connected;
                $wasStale  = $row->seen_at ? $row->seen_at->lt($now->copy()->subSeconds(self::STALE_SECONDS)) : true;

                // Always update fields
                $row->fill([
                    'vpn_user_id'     => $uid,
                    'protocol'        => 'WIREGUARD',
                    'public_key'      => $pub,
                    'client_ip'       => $p['client_ip'] ?? $row->client_ip,
                    'virtual_ip'      => $p['virtual_ip'] ?? $row->virtual_ip,
                    'bytes_received'  => (int) ($p['bytes_received'] ?? ($p['bytes_in'] ?? 0)),
                    'bytes_sent'      => (int) ($p['bytes_sent'] ?? ($p['bytes_out'] ?? 0)),
                    'seen_at'         => $seenAt,
                    'is_connected'    => $isOnline,
                    'disconnected_at' => $isOnline ? null : ($row->disconnected_at ?? $now),
                ]);

                // Session start rules (WireGuard):
                // - if it becomes online from offline OR was stale, start a fresh session
                if ($isOnline && (!$wasOnline || $wasStale || !$row->connected_at)) {
                    $row->connected_at = $now;
                }

                // If going offline, set duration
                if (!$isOnline && $wasOnline) {
                    $row->session_duration = $row->connected_at ? $now->diffInSeconds($row->connected_at) : null;
                    $row->disconnected_at  = $now;
                }

                $row->save();

                // update vpn_users
                if ($isOnline) {
                    $userUpdate = ['is_online' => true, 'last_ip' => $row->client_ip];
                    if (Schema::hasColumn('vpn_users', 'last_protocol')) $userUpdate['last_protocol'] = 'WIREGUARD';
                    VpnUser::whereKey($uid)->update($userUpdate);
                }
            }

            // Mark missing/stale sessions offline (anything not touched this push OR stale by seen_at)
            $this->disconnectMissingOrStale($server->id, $touched, $now);

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

    private function disconnectMissingOrStale(int $serverId, array $touched, Carbon $now): void
    {
        $cutoff = $now->copy()->subSeconds(self::STALE_SECONDS);

        $q = VpnUserConnection::where('vpn_server_id', $serverId)
            ->where('protocol', 'WIREGUARD')
            ->where('is_connected', true)
            ->where(function ($qq) use ($touched, $cutoff) {
                if (!empty($touched)) {
                    $qq->whereNotIn('session_key', $touched);
                }
                $qq->orWhereNull('seen_at')->orWhere('seen_at', '<', $cutoff);
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
                'seen_at'       => $hasSeenAt ? optional($r->seen_at)?->toIso8601String() : optional($r->updated_at)?->toIso8601String(),
                'bytes_in'      => (int) $r->bytes_received,
                'bytes_out'     => (int) $r->bytes_sent,
                'server_name'   => $server->name,
                'protocol'      => 'WIREGUARD',
                'session_key'   => $r->session_key,
                'public_key'    => $r->public_key,
            ])->values()->all();
    }

    private function extractSeenAt(array $peer): ?Carbon
    {
        // Prefer explicit seen_at if provided (ISO string, unix seconds/ms/ns, etc)
        $seen = $peer['seen_at'] ?? $peer['seenAt'] ?? null;
        if ($seen !== null && $seen !== '') {
            return $this->parseTime($seen);
        }

        // Fall back to handshake timestamp (unix seconds, sometimes ms/ns)
        $raw = $peer['handshake'] ?? $peer['latest_handshake'] ?? $peer['latestHandshake'] ?? null;
        if ($raw === null || $raw === '') return null;

        $n = (int) $raw;
        if ($n <= 0) return null;

        // tolerate ms / ns
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
            $n = (int) $value;
            if ($n <= 0) return null;
            return $this->extractSeenAt(['handshake' => $n]);
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