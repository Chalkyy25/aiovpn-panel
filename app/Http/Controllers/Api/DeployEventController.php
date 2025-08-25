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
            'status'   => 'required|string',   // e.g. "mgmt"
            'message'  => 'nullable|string',
            'ts'       => 'nullable|string',
            'users'    => 'nullable|array',    // [{ username: "alice" }]
            'cn_list'  => 'nullable|string',   // "alice,bob"
            'clients'  => 'nullable|integer',
        ]);

        $status  = strtolower($data['status']);
        $message = (string) ($data['message'] ?? '');
        $ts      = $data['ts'] ?? now()->toAtomString();

        Log::info("APPEND_LOG: [{$status}] {$message}");

        if ($status !== 'mgmt') {
            return response()->json(['ok' => true]);
        }

        // ── Parse users from payload ─────────────────────────────
        $usernames = [];

        if (!empty($data['users'])) {
            foreach ($data['users'] as $u) {
                $name = $u['username'] ?? $u['cn'] ?? null;
                if ($name) $usernames[] = $name;
            }
        } elseif (!empty($data['cn_list'])) {
            $usernames = array_filter(array_map('trim', explode(',', $data['cn_list'])));
        } elseif ($message !== '') {
            if (preg_match('/clients\s*=\s*\d+\s*\[([^\]]*)\]/i', $message, $m)) {
                $usernames = array_filter(array_map('trim', explode(',', $m[1] ?? '')));
            }
        }

        $clients = count($usernames);

        // ── Sync DB state ───────────────────────────────────────
        DB::transaction(function () use ($server, $usernames) {
            $now = now();
            $idByUsername = VpnUser::whereIn('username', $usernames)->pluck('id', 'username');
            $connectedIds = [];

            foreach ($usernames as $name) {
                $uid = $idByUsername[$name] ?? null;
                if (!$uid) continue;

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

            // Disconnect everyone else
            $toDisconnect = VpnUserConnection::query()
                ->where('vpn_server_id', $server->id)
                ->where('is_connected', true)
                ->when(!empty($connectedIds), fn($q) => $q->whereNotIn('vpn_user_id', $connectedIds))
                ->get();

            foreach ($toDisconnect as $row) {
                $row->update([
                    'is_connected'    => false,
                    'disconnected_at' => $now,
                ]);
                VpnUserConnection::updateUserOnlineStatusIfNoActiveConnections($row->vpn_user_id);
            }
        });

        // ── Broadcast to dashboard ──────────────────────────────
        broadcast(new ServerMgmtEvent(
            $server->id,
            $ts,
            array_map(fn($u) => ['username' => $u], $usernames), // users[]
            null,
            $message ?: 'mgmt'
        ))->toOthers();

        return response()->json([
            'ok'        => true,
            'server_id' => $server->id,
            'clients'   => $clients,
            'cn_list'   => implode(',', $usernames),
        ]);
    }
}