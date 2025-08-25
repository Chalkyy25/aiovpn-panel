<?php

namespace App\Http\Controllers\Api;

use App\Events\ServerMgmtEvent;
use App\Http\Controllers\Controller;
use App\Models\VpnServer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class DeployEventController extends Controller
{
    public function store(Request $request, VpnServer $server): JsonResponse
    {
        // Validate but stay flexible — we accept any of: users[], cn_list, or a plain "message"
        $data = $request->validate([
            'status'   => 'required|string',          // e.g. "mgmt"
            'message'  => 'nullable|string',          // e.g. "ts=... clients=1 [alice]"
            'ts'       => 'nullable|string',          // optional ISO 8601
            'users'    => 'nullable|array',           // [{ username: "alice", ... }]
            'cn_list'  => 'nullable|string',          // "alice,bob"
            'clients'  => 'nullable|integer',         // explicit override
        ]);

        $status  = strtolower($data['status']);
        $message = (string) ($data['message'] ?? '');

        // Keep your existing APPEND_LOG line intact
        Log::info("APPEND_LOG: [{$status}] {$message}");

        // Only broadcast realtime updates for mgmt status posts
        if ($status !== 'mgmt') {
            return response()->json(['ok' => true]);
        }

        // ── Build the user list from the most reliable source available ─────────
        // 1) users[] (objects with username or cn)
        $names = [];
        if (!empty($data['users']) && is_array($data['users'])) {
            foreach ($data['users'] as $u) {
                $name = $u['username'] ?? $u['cn'] ?? null;
                if (is_string($name) && $name !== '') {
                    $names[] = $name;
                }
            }
        }

        // 2) cn_list string
        if (!$names && !empty($data['cn_list'])) {
            $names = array_values(array_filter(array_map('trim', explode(',', $data['cn_list']))));
        }

        // 3) parse from message: supports "... clients=N [a,b,c]" anywhere in the line
        if (!$names && $message !== '') {
            if (preg_match('/clients\s*=\s*(\d+)\s*\[([^\]]*)\]/i', $message, $m)) {
                $list = trim($m[2] ?? '');
                if ($list !== '') {
                    $names = array_values(array_filter(array_map('trim', explode(',', $list))));
                } else {
                    $names = []; // empty bracket list means zero
                }
            }
        }

        $cnList  = implode(',', $names);
        $clients = isset($data['clients'])
            ? (int) $data['clients']
            : ($cnList === '' ? 0 : count($names));

        $ts  = (string) ($data['ts'] ?? now()->toAtomString());
        $raw = $message !== '' ? $message : 'mgmt';

        // ❗ Use positional args (avoid the “Unknown named parameter $clients” issue)
        // ServerMgmtEvent::__construct(int $serverId, string $ts, int $clients, string $cnList, string $raw)
        broadcast(new ServerMgmtEvent($server->id, $ts, $clients, $cnList, $raw))->toOthers();

        return response()->json([
            'ok'        => true,
            'server_id' => $server->id,
            'clients'   => $clients,
            'cn_list'   => $cnList,
        ]);
    }
}