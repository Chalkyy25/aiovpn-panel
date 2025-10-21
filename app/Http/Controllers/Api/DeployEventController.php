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

        if ($status !== 'mgmt') {
            Log::channel('vpn')->debug("DeployEventController: non-mgmt status='{$status}' for server #{$server->id}");
            return response()->json(['ok' => true]);
        }

        // Normalize incoming → [{ username, client_ip?, virtual_ip?, bytes_in?, bytes_out?, connected_at? }]
        $incomingAll = $this->normaliseIncoming($data);

        // Filter to *valid-looking* usernames only (avoid blanks/UNDEF/etc.)
        $incoming = array_values(array_filter($incomingAll, function ($r) {
            $name = trim((string)($r['username'] ?? ''));
            if ($name === '' || strcasecmp($name, 'UNDEF') === 0 || strcasecmp($name, 'unknown') === 0) {
                return false;
            }
            // Optional: policy (3–32 chars letters/digits/._-)
            return (bool) preg_match('/^[A-Za-z0-9._-]{3,32}$/', $name);
        }));

        $now = now();

        Log::channel('vpn')->debug(sprintf(
            'MGMT EVENT server=%d ts=%s incoming=%d filtered=%d [%s]',
            $server->id, $ts, count($incomingAll), count($incoming), implode(',', array_column($incoming, 'username'))
        ));

        DB::transaction(function () use ($server, $incoming, $now) {
            // Only consider users that already exist in DB (panel-created).
            $names    = array_column($incoming, 'username');
            $idByName = VpnUser::whereIn('username', $names)->pluck('id', 'username');

            $stillConnectedUserIds = [];

            foreach ($incoming as $c) {
                $username = trim((string) $c['username']);
                $uid      = $idByName[$username] ?? null;

                // If user is not known, skip silently (do NOT create).
                if (!$uid) {
                    Log::channel('vpn')->notice("MGMT: skipping unknown user '{$username}' on server {$server->id}");
                    continue;
                }

                $stillConnectedUserIds[] = $uid;

                /** @var VpnUserConnection $row */
                $row = VpnUserConnection::firstOrCreate([
                    'vpn_user_id'   => $uid,
                    'vpn_server_id' => $server->id,
                ]);

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

                $row->save();

                VpnUser::whereKey($uid)->update([
                    'is_online' => true,
                    'last_ip'   => $row->client_ip,
                ]);
            }

            // Disconnect any users that vanished from this server (only consider rows we track)
            $toDisconnect = VpnUserConnection::query()
                ->where('vpn_server_id', $server->id)
                ->where('is_connected', true)
                ->when(!empty($stillConnectedUserIds), fn ($q) => $q->whereNotIn('vpn_user_id', $stillConnectedUserIds))
                ->get();

            foreach ($toDisconnect as $row) {
                $row->update([
                    'is_connected'     => false,
                    'disconnected_at'  => $now,
                    'session_duration' => $row->connected_at ? $now->diffInSeconds($row->connected_at) : null,
                ]);
                VpnUserConnection::updateUserOnlineStatusIfNoActiveConnections($row->vpn_user_id);
            }

            // Persist live count as the number of known (panel) users currently connected on this server
            $liveKnown = VpnUserConnection::query()
                ->where('vpn_server_id', $server->id)
                ->where('is_connected', true)
                ->count();

            $update = [];
            if (\Schema::hasColumn('vpn_servers', 'online_users')) {
                $update['online_users'] = $liveKnown;
            }
            if (\Schema::hasColumn('vpn_servers', 'last_mgmt_at')) {
                $update['last_mgmt_at'] = $now;
            }
            if (!empty($update)) {
                $server->forceFill($update)->saveQuietly();
            }
        });

        // Enrich & broadcast for the dashboard
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
            'clients'   => count($enriched), // only known/panel users
            'users'     => $enriched,
        ]);
    }

    // ───────────────────────────── helpers ─────────────────────────────

    /** Accepts any incoming shape and returns canonical array of users. */
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
                    $out[] = ['username' => $name];
                }
            }
        }

        // Unique by username
        $seen = [];
        return array_values(array_filter($out, function ($r) use (&$seen) {
            $name = $r['username'] ?? null;
            if (!$name || isset($seen[$name])) return false;
            $seen[$name] = true;
            return true;
        }));
    }

    /** Normalise one user item from various shapes/keys into our canonical keys. */
    private function normaliseUserItem($u): array
    {
        if (is_string($u)) $u = ['username' => $u];
        $u = (array) $u;

        $username = $u['username']
            ?? $u['cn']
            ?? $u['CommonName']
            ?? 'unknown';

        // Real Address can be "IP:PORT"
        $clientIp = $u['client_ip']
            ?? $u['RealAddress']
            ?? $u['real_ip']
            ?? null;
        if (is_string($clientIp) && str_contains($clientIp, ':')) {
            $clientIp = explode(':', $clientIp, 2)[0];
        }

        // Virtual Address may include mask (strip it)
        $virt = $u['virtual_ip']
            ?? $u['VirtualAddress']
            ?? $u['virtual_address']
            ?? null;
        if (is_string($virt) && str_contains($virt, '/')) {
            $virt = explode('/', $virt, 2)[0];
        }

        // connected_at can be ISO, epoch seconds, epoch ms, or "seconds ago"
        $connectedAt = $u['connected_at']
            ?? $u['ConnectedSince']
            ?? $u['connected_since']
            ?? $u['Connected Since (time_t)']
            ?? $u['connected_seconds']
            ?? null;

        // Bytes (accept several key styles)
        $bytesIn  = (int) ($u['bytes_in']
            ?? $u['BytesReceived']
            ?? $u['bytes_received']
            ?? 0);

        $bytesOut = (int) ($u['bytes_out']
            ?? $u['BytesSent']
            ?? $u['bytes_sent']
            ?? 0);

        return [
            'username'     => (string) $username,
            'client_ip'    => $clientIp ?: null,
            'virtual_ip'   => $virt ?: null,
            'connected_at' => $this->connectedAtToIso($connectedAt),
            'bytes_in'     => $bytesIn,
            'bytes_out'    => $bytesOut,
        ];
    }

    /** Return ISO8601 string for assorted timestamp inputs. */
    private function connectedAtToIso($value): ?string
    {
        if ($value === null || $value === '') return null;

        try {
            if (is_numeric($value)) {
                $n = (int) $value;
                if ($n > 2_000_000_000_000) {            // ms since epoch
                    return Carbon::createFromTimestampMs($n)->toIso8601String();
                }
                // If it's a "seconds ago" small number (e.g. < 10y) AND smaller than epoch delta, treat as duration
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
                if ($n > 2_000_000_000_000) return Carbon::createFromTimestampMs($n);
                if ($n < 315_576_000 && $n < (time() - 946_684_800)) return now()->subSeconds($n);
                return Carbon::createFromTimestamp($n);
            }
            return Carbon::parse((string) $value);
        } catch (\Throwable) {
            return null;
        }
    }

    /** Build a rich list from DB so the dashboard gets complete info instantly. */
    private function enrichFromDb(VpnServer $server): array
    {
        $rows = VpnUserConnection::query()
            ->with('vpnUser:id,username')
            ->where('vpn_server_id', $server->id)
            ->where('is_connected', true)
            ->get();

        return $rows->map(function (VpnUserConnection $r) use ($server) {
            return [
                'connection_id' => $r->id,
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