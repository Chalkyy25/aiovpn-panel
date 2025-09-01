<?php

namespace App\Http\Controllers\Api;

use App\Events\ServerMgmtEvent;
use App\Http\Controllers\Controller;
use App\Models\VpnServer;
use App\Models\VpnUser;
use App\Models\VpnUserConnection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class DeployEventController extends Controller
{
    public function store(Request $request, VpnServer $server): JsonResponse
    {
        $data = $request->validate([
            'status'   => 'required|string',     // e.g. "mgmt"
            'message'  => 'nullable|string',     // optional raw line
            'ts'       => 'nullable|string',     // ISO8601 timestamp
            'users'    => 'nullable|array',      // [{ username, client_ip, ... }]
            'cn_list'  => 'nullable|string',     // "alice,bob"
            'clients'  => 'nullable|integer',    // explicit override
        ]);

        $status = strtolower($data['status']);
        $ts     = $data['ts'] ?? now()->toAtomString();
        $raw    = $data['message'] ?? 'mgmt';
        $now    = now();

        // ── Normalize to full client objects ────────────────
        $clients = [];

        if (!empty($data['users']) && is_array($data['users'][0] ?? null)) {
            $clients = $data['users']; // already full
        } elseif (!empty($data['users'])) {
            foreach ($data['users'] as $u) {
                $clients[] = ['username' => $u['username'] ?? (string) $u];
            }
        } elseif (!empty($data['cn_list'])) {
            foreach (explode(',', $data['cn_list']) as $name) {
                $name = trim($name);
                if ($name !== '') {
                    $clients[] = ['username' => $name];
                }
            }
        }

        $clients = collect($clients)
            ->filter(fn ($c) => !empty($c['username']))
            ->unique('username')
            ->values()
            ->all();

        $clientCount = $data['clients'] ?? count($clients);

        // ── Log ─────────────────────────────────────────────
        Log::channel('vpn')->debug(sprintf(
            'APPEND_LOG: [%s] ts=%s server=%d clients=%d [%s]',
            $status,
            $ts,
            $server->id,
            $clientCount,
            implode(',', array_column($clients, 'username'))
        ));
        if ($status !== 'mgmt') {
            return response()->json(['ok' => true]);
        }

        // ── Sync DB snapshot ───────────────────────────────
        DB::transaction(function () use ($server, $clients, $now) {
            $usernames = array_column($clients, 'username');
            $existing  = VpnUser::whereIn('username', $usernames)->pluck('id', 'username');
            $connectedIds = [];

            foreach ($clients as $c) {
                $username = $c['username'];
                $uid = $existing[$username] ?? null;

                if (!$uid) {
                    $uid = VpnUser::create([
                        'username' => $username,
                        'is_online' => false,
                    ])->id;
                    $existing[$username] = $uid;
                }

                $connectedIds[] = $uid;

                $row = VpnUserConnection::firstOrCreate([
                    'vpn_user_id'   => $uid,
                    'vpn_server_id' => $server->id,
                ]);

                if (!$row->is_connected) {
                    $row->connected_at    = $now;
                    $row->disconnected_at = null;
                }

                $row->is_connected = true;

                // fill stats if available
                if (!empty($c['client_ip'])) {
                    $row->client_ip = $c['client_ip'];
                }
                if (!empty($c['virtual_ip'])) {
                    $row->virtual_ip = $c['virtual_ip'];
                }
                if (!empty($c['bytes_received'])) {
                    $row->bytes_received = (int) $c['bytes_received'];
                }
                if (!empty($c['bytes_sent'])) {
                    $row->bytes_sent = (int) $c['bytes_sent'];
                }
                if (!empty($c['connected_at'])) {
                    $row->connected_at = Carbon::createFromTimestamp($c['connected_at']);
                }

                $row->save();

                VpnUser::whereKey($uid)->update([
                    'is_online' => true,
                    'last_ip'   => $row->client_ip,
                ]);
            }

            // Disconnect missing
            $toDisconnect = VpnUserConnection::query()
                ->where('vpn_server_id', $server->id)
                ->where('is_connected', true)
                ->when(!empty($connectedIds), fn ($q) => $q->whereNotIn('vpn_user_id', $connectedIds))
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

            $server->forceFill([
                'online_users' => count($clients),
                'last_sync_at' => $now,
            ])->saveQuietly();
        });

        // ── Broadcast ─────────────────────────────────────
        broadcast(new ServerMgmtEvent(
            $server->id,
            $ts,
            $clients,
            implode(',', array_column($clients, 'username')),
            $raw
        ))->toOthers();

        return response()->json([
            'ok'        => true,
            'server_id' => $server->id,
            'clients'   => $clientCount,
            'cn_list'   => implode(',', array_column($clients, 'username')),
        ]);
    }
}