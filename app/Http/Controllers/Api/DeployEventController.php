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
            'users'    => 'nullable|array',      // [{ username: "alice" }]
            'cn_list'  => 'nullable|string',     // "alice,bob"
            'clients'  => 'nullable|integer',    // explicit override
        ]);

        $status = strtolower($data['status']);
        $ts     = $data['ts'] ?? now()->toAtomString();
        $raw    = $data['message'] ?? 'mgmt';

        // ── Extract usernames ───────────────────────────────
        $usernames = [];

        if (!empty($data['users'])) {
            foreach ($data['users'] as $u) {
                $name = $u['username'] ?? $u['cn'] ?? null;
                if (is_string($name) && $name !== '') {
                    $usernames[] = trim($name);
                }
            }
        }

        if (!$usernames && !empty($data['cn_list'])) {
            $usernames = array_values(array_filter(
                array_map('trim', explode(',', $data['cn_list']))
            ));
        }

        if (!$usernames && $raw &&
            preg_match('/clients\s*=\s*(\d+)\s*\[([^\]]*)\]/i', $raw, $m)) {
            $list = trim($m[2] ?? '');
            $usernames = $list !== '' ? array_filter(array_map('trim', explode(',', $list))) : [];
        }

        $usernames = array_values(array_unique($usernames)); // ensure unique
        $clients   = $data['clients'] ?? count($usernames);
        $now       = now();

        // ── Log APPEND_LOG with details ─────────────────────
        Log::info(sprintf(
            'APPEND_LOG: [%s] ts=%s server=%d clients=%d [%s]',
            $status,
            $ts,
            $server->id,
            $clients,
            implode(',', $usernames)
        ));

        if ($status !== 'mgmt') {
            return response()->json(['ok' => true]);
        }

        // ── Sync DB snapshot ────────────────────────────────
        DB::transaction(function () use ($server, $usernames, $now) {
            $existing = VpnUser::whereIn('username', $usernames)->pluck('id', 'username');
            $connectedIds = [];

            foreach ($usernames as $username) {
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
                $row->save();

                VpnUser::whereKey($uid)->update([
                    'is_online' => true,
                    'last_ip'   => $row->client_ip,
                ]);
            }

            // Disconnect missing ones
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
                'online_users' => count($usernames),
                'last_sync_at' => $now,
            ])->saveQuietly();
        });

        // ── Broadcast snapshot ──────────────────────────────
        broadcast(new ServerMgmtEvent(
            $server->id,
            $ts,
            array_map(fn ($u) => ['username' => $u], $usernames),
            implode(',', $usernames),
            $raw
        ))->toOthers();

        // ── API response ───────────────────────────────────
        return response()->json([
            'ok'        => true,
            'server_id' => $server->id,
            'clients'   => $clients,
            'cn_list'   => implode(',', $usernames),
        ]);
    }
}