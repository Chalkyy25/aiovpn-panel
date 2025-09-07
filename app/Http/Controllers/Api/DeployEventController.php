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

class DeployEventController extends Controller
{
    public function store(Request $request, VpnServer $server): JsonResponse
    {
        $data = $request->validate([
            'status'   => 'required|string',
            'message'  => 'nullable|string',
            'ts'       => 'nullable|string',
            'users'    => 'nullable|array',   // array<string|object>
            'cn_list'  => 'nullable|string',
            'clients'  => 'nullable|integer',
        ]);

        $ts   = $data['ts'] ?? now()->toIso8601String();
        $raw  = $data['message'] ?? 'mgmt';
        $now  = now();

        // -------- normalise incoming to array of { username, ... } ----------
        $incoming = $this->normaliseIncoming($data);

        Log::channel('vpn')->debug(sprintf(
            'MGMT EVENT server=%d users=%d [%s]',
            $server->id, count($incoming), implode(',', array_column($incoming, 'username'))
        ));

        // -------- persist snapshot (your existing behaviour) ----------------
        DB::transaction(function () use ($server, $incoming, $now) {
            $names = array_column($incoming, 'username');
            $existing = VpnUser::whereIn('username', $names)->pluck('id', 'username');

            $stillConnectedUserIds = [];

            foreach ($incoming as $c) {
                $username = $c['username'];
                $uid = $existing[$username] ?? null;

                if (!$uid) {
                    $uid = VpnUser::create(['username' => $username, 'is_online' => false])->id;
                    $existing[$username] = $uid;
                }

                $stillConnectedUserIds[] = $uid;

                $row = VpnUserConnection::firstOrCreate([
                    'vpn_user_id'   => $uid,
                    'vpn_server_id' => $server->id,
                ]);

                if (!$row->is_connected) {
                    $row->connected_at    = $now;
                    $row->disconnected_at = null;
                }

                $row->is_connected = true;

                // hydrate if present
                $row->client_ip       = $c['client_ip']   ?? $row->client_ip;
                $row->virtual_ip      = $c['virtual_ip']  ?? $row->virtual_ip;
                $row->bytes_received  = $c['bytes_in']    ?? $c['bytes_received'] ?? $row->bytes_received;
                $row->bytes_sent      = $c['bytes_out']   ?? $c['bytes_sent']     ?? $row->bytes_sent;

                if (!empty($c['connected_at'])) {
                    $row->connected_at = $this->parseConnectedAt($c['connected_at']);
                }

                $row->save();

                VpnUser::whereKey($uid)->update([
                    'is_online' => true,
                    'last_ip'   => $row->client_ip,
                ]);
            }

            // disconnect users that vanished from this server
            $toDisconnect = VpnUserConnection::query()
                ->where('vpn_server_id', $server->id)
                ->where('is_connected', true)
                ->when(!empty($stillConnectedUserIds), fn($q) => $q->whereNotIn('vpn_user_id', $stillConnectedUserIds))
                ->get();

            foreach ($toDisconnect as $row) {
                $row->update([
                    'is_connected'     => false,
                    'disconnected_at'  => $now,
                    'session_duration' => $row->connected_at ? $now->diffInSeconds($row->connected_at) : null,
                ]);
                VpnUserConnection::updateUserOnlineStatusIfNoActiveConnections($row->vpn_user_id);
            }

           $server->forceFill([
            'online_count' => $clientCount,                                  // integer
            'last_mgmt_at' => $now,                                          // datetime
            'online_users' => array_column($clients, 'username'),            // optional: keep list in JSON
        ])->saveQuietly();
        
        // -------- enrich from DB so every row has full details --------------
        $enriched = $this->enrichFromDb($server);

        // -------- broadcast to fleet + server channels ----------------------
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

    // --- helpers ------------------------------------------------------------

    /** Accepts any incoming shape and returns canonical array of users */
    private function normaliseIncoming(array $data): array
    {
        $out = [];

        if (is_array($data['users'] ?? null)) {
            foreach ($data['users'] as $u) {
                $out[] = $this->normaliseUserItem($u);
            }
        } elseif (!empty($data['cn_list'])) {
            foreach (explode(',', $data['cn_list']) as $name) {
                $name = trim($name);
                if ($name !== '') {
                    $out[] = ['username' => $name];
                }
            }
        }

        // unique by username
        $seen = [];
        return array_values(array_filter($out, function ($r) use (&$seen) {
            if (!isset($r['username'])) return false;
            if (isset($seen[$r['username']])) return false;
            $seen[$r['username']] = true;
            return true;
        }));
    }

    private function normaliseUserItem($u): array
    {
        if (is_string($u)) $u = ['username' => $u];

        $username = $u['username'] ?? $u['cn'] ?? $u['CommonName'] ?? 'unknown';

        // RealAddress "IP:PORT" → strip port
        $clientIp = $u['client_ip'] ?? $u['RealAddress'] ?? null;
        if (is_string($clientIp) && str_contains($clientIp, ':')) {
            $clientIp = explode(':', $clientIp, 2)[0];
        }

        // VirtualAddress "10.8.0.2/24" → strip mask
        $virt = $u['virtual_ip'] ?? $u['VirtualAddress'] ?? null;
        if (is_string($virt) && str_contains($virt, '/')) {
            $virt = explode('/', $virt, 2)[0];
        }

        // connected_at may be ISO, epoch sec, epoch ms, or "connected_seconds" ago
        $connectedAt = $u['connected_at']
            ?? $u['ConnectedSince']
            ?? $u['connected_since']
            ?? $u['connected_seconds']
            ?? null;

        $connectedAtIso = $this->connectedAtToIso($connectedAt);

        // bytes
        $bytesIn  = (int)($u['bytes_in'] ?? $u['BytesReceived'] ?? $u['bytes_received'] ?? 0);
        $bytesOut = (int)($u['bytes_out'] ?? $u['BytesSent']    ?? $u['bytes_sent']    ?? 0);

        return [
            'username'     => $username,
            'client_ip'    => $clientIp,
            'virtual_ip'   => $virt,
            'connected_at' => $connectedAtIso,
            'bytes_in'     => $bytesIn,
            'bytes_out'    => $bytesOut,
        ];
    }

    private function connectedAtToIso($value): string
    {
        if ($value === null || $value === '') {
            return now()->toIso8601String();
        }
        // numeric seconds / ms / “seconds ago”
        if (is_numeric($value)) {
            $n = (int)$value;
            // Heuristic: > 10^12 → ms
            if ($n > 20000000000) return Carbon::createFromTimestampMs($n)->toIso8601String();
            // If it’s “seconds ago” (small-ish) assume it's a duration, not epoch
            if ($n < 315576000 && $n < (time() - 946684800)) { // <10 years and smaller than epoch delta
                return now()->subSeconds($n)->toIso8601String();
            }
            return Carbon::createFromTimestamp($n)->toIso8601String();
        }
        // assume ISO-ish
        return Carbon::parse($value)->toIso8601String();
    }

    private function parseConnectedAt($value): ?Carbon
    {
        try {
            if (is_numeric($value)) {
                $n = (int)$value;
                if ($n > 20000000000) return Carbon::createFromTimestampMs($n);
                if ($n < 315576000 && $n < (time() - 946684800)) return now()->subSeconds($n);
                return Carbon::createFromTimestamp($n);
            }
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /** Build a rich list from DB so the dashboard has complete info instantly */
    private function enrichFromDb(VpnServer $server): array
    {
        $rows = VpnUserConnection::query()
            ->with('vpnUser:id,username')
            ->where('vpn_server_id', $server->id)
            ->where('is_connected', true)
            ->get();

        return $rows->map(function (VpnUserConnection $r) use ($server) {
            return [
                'connection_id' => $r->id, // stable key for Alpine de-dupe
                'username'      => optional($r->vpnUser)->username ?? 'unknown',
                'client_ip'     => $r->client_ip,
                'virtual_ip'    => $r->virtual_ip,
                'connected_at'  => optional($r->connected_at)?->toIso8601String(),
                'bytes_in'      => (int) $r->bytes_received,
                'bytes_out'     => (int) $r->bytes_sent,
                'server_name'   => $server->name,
            ];
        })->values()->all();
    }
}