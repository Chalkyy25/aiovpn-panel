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
            'status'   => 'required|string',
            'message'  => 'nullable|string',
            'ts'       => 'nullable|string',
            'users'    => 'nullable|array',
            'cn_list'  => 'nullable|string',
            'clients'  => 'nullable|integer',
        ]);

        $status  = strtolower($data['status']);
        $message = (string) ($data['message'] ?? '');

        Log::info("APPEND_LOG: [{$status}] {$message}");

        if ($status !== 'mgmt') {
            return response()->json(['ok' => true]);
        }

        // ── Build usernames ────────────────────────────────
        $names = [];

        if (!empty($data['users']) && is_array($data['users'])) {
            foreach ($data['users'] as $u) {
                $name = $u['username'] ?? $u['cn'] ?? null;
                if (is_string($name) && $name !== '') {
                    $names[] = $name;
                }
            }
        }

        if (!$names && !empty($data['cn_list'])) {
            $names = array_values(array_filter(array_map('trim', explode(',', $data['cn_list']))));
        }

        if (!$names && $message !== '') {
            if (preg_match('/clients\s*=\s*(\d+)\s*\[([^\]]*)\]/i', $message, $m)) {
                $list = trim($m[2] ?? '');
                $names = $list !== '' ? array_values(array_filter(array_map('trim', explode(',', $list)))) : [];
            }
        }

        $ts      = (string) ($data['ts'] ?? now()->toAtomString());
        $clients = isset($data['clients']) ? (int) $data['clients'] : count($names);
        $cnList  = implode(',', $names);
        $raw     = $message !== '' ? $message : 'mgmt';

        // ── Sync DB with this snapshot ─────────────────────
        DB::transaction(function () use ($server, $names) {
            $now = now();
            $userIds = VpnUser::whereIn('username', $names)->pluck('id', 'username');
            $connectedIds = [];

            foreach ($names as $username) {
                $uid = $userIds[$username] ?? null;
                if (!$uid) continue;

                $connectedIds[] = $uid;

                $row = VpnUserConnection::firstOrCreate([
                    'vpn_user_id'   => $uid,
                    'vpn_server_id' => $server->id,
                ]);

                if (!$row->is_connected) {
                    $row->connected_at = $now;
                    $row->disconnected_at = null;
                }

                $row->is_connected = true;
                $row->save();

                VpnUser::whereKey($uid)->update([
                    'is_online' => true,
                    'last_ip'   => $row->client_ip,
                ]);
            }

            // Mark others offline
            VpnUserConnection::query()
                ->where('vpn_server_id', $server->id)
                ->where('is_connected', true)
                ->when(!empty($connectedIds), fn($q) => $q->whereNotIn('vpn_user_id', $connectedIds))
                ->update([
                    'is_connected'    => false,
                    'disconnected_at' => $now,
                ]);
        });

        // ── Broadcast realtime event ───────────────────────
        broadcast(new ServerMgmtEvent(
            $server->id,
            $ts,
            $names,    // array → Event will derive clients + cnList
            null,
            $raw
        ))->toOthers();

        return response()->json([
            'ok'        => true,
            'server_id' => $server->id,
            'clients'   => $clients,
            'cn_list'   => $cnList,
        ]);
    }
}