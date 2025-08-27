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

class DeployEventController extends Controller
{
    public function store(Request $request, VpnServer $server): JsonResponse
    {
        $data = $request->validate([
            'status'   => 'required|string',     // e.g. "mgmt"
            'message'  => 'nullable|string',     // optional raw line
            'ts'       => 'nullable|string',     // ISO8601 timestamp
            'users'    => 'nullable|array',      // [{ username: "alice", client_ip: "...", ... }]
            'cn_list'  => 'nullable|string',     // "alice,bob"
            'clients'  => 'nullable|integer',    // explicit override
        ]);

        $status = strtolower($data['status']);
        $ts     = $data['ts'] ?? now()->toAtomString();
        $raw    = $data['message'] ?? 'mgmt';
        $now    = now();

        // ── Normalise users into full client objects ──────────────
        $clients = [];

        if (!empty($data['users']) && is_array($data['users'][0] ?? null)) {
            // Already full client objects
            $clients = $data['users'];
        } elseif (!empty($data['users'])) {
            // Array of usernames only
            foreach ($data['users'] as $u) {
                $name = $u['username'] ?? $u['cn'] ?? (string) $u;
                if ($name) {
                    $clients[] = ['username' => trim($name)];
                }
            }
        } elseif (!empty($data['cn_list'])) {
            foreach (explode(',', $data['cn_list']) as $name) {
                $name = trim($name);
                if ($name !== '') {
                    $clients[] = ['username' => $name];
                }
            }
        } elseif ($raw && preg_match('/clients\s*=\s*(\d+)\s*\[([^\]]*)\]/i', $raw, $m)) {
            foreach (explode(',', $m[2] ?? '') as $name) {
                $name = trim($name);
                if ($name !== '') {
                    $clients[] = ['username' => $name];
                }
            }
        }

        // Unique + clean
        $clients = collect($clients)
            ->filter(fn ($c) => !empty($c['username']))
            ->unique('username')
            ->values()
            ->all();

        $clientCount = $data['clients'] ?? count($clients);

        // ── Log APPEND_LOG ─────────────────────────────────
        Log::info(sprintf(
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
            $existing = VpnUser::whereIn('username', $usernames)->pluck('id', 'username');
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
                // Fill in live fields if provided
                if (!empty($c['client_ip'])) {
                    $row->client_ip = $c['client_ip'];
                }
                if (!empty($c['virtual_ip'])) {
                    $row->virtual_ip = $c['virtual_ip'];
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
                    'is_connected'    => false,
                    'disconnected_at' => $now,
                ]);
                VpnUserConnection::updateUserOnlineStatusIfNoActiveConnections($row->vpn_user_id);
            }

            $server->forceFill([
                'online_users' => count($clients),
                'last_sync_at' => $now,
            ])->saveQuietly();
        });

        // ── Broadcast full client objects ──────────────────
        broadcast(new ServerMgmtEvent(
            $server->id,
            $ts,
            $clients,
            implode(',', array_column($clients, 'username')),
            $raw
        ))->toOthers();

        // ── API response ───────────────────────────────────
        return response()->json([
            'ok'        => true,
            'server_id' => $server->id,
            'clients'   => $clientCount,
            'cn_list'   => implode(',', array_column($clients, 'username')),
        ]);
    }
}