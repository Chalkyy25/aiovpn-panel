<?php

namespace App\Http\Controllers;

use App\Models\VpnServer;
use App\Events\ServerMgmtEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Carbon;
use App\Events\ServerMgmtUpdated;

class DeployApiController extends Controller
{
    public function facts(Request $req, VpnServer $server)
{
    $data = $req->validate([
        // existing fields
        'iface'      => 'nullable|string',
        'mgmt_port'  => 'nullable|integer',
        'vpn_port'   => 'nullable|integer',
        'proto'      => 'nullable|string|in:udp,tcp,openvpn,wireguard',
        'ip_forward' => 'nullable|boolean',

        // NEW: WireGuard + endpoint/DNS facts
        'public_ip'     => 'nullable|ip',
        'wg_public_key' => 'nullable|string',
        'wg_port'       => 'nullable|integer',
        'wg_subnet'     => 'nullable|string',
        'dns'           => 'nullable|string',
    ]);

    $dirty = false;

    // ---- existing bits ----
    if (array_key_exists('iface', $data) && $data['iface'] !== null && $data['iface'] !== $server->iface) {
        $server->iface = $data['iface'];
        $dirty = true;
    }

    if (array_key_exists('mgmt_port', $data) && $data['mgmt_port'] !== null && (int) $data['mgmt_port'] !== (int) $server->mgmt_port) {
        $server->mgmt_port = (int) $data['mgmt_port'];
        $dirty = true;
    }

    if (array_key_exists('vpn_port', $data) && $data['vpn_port'] !== null && (int) $data['vpn_port'] !== (int) $server->port) {
        $server->port = (int) $data['vpn_port'];
        $dirty = true;
    }

    if (!empty($data['proto']) && $data['proto'] !== $server->protocol) {
        $server->protocol = $data['proto'];
        $dirty = true;
    }

    // optional: if you have an ip_forward column
    if (array_key_exists('ip_forward', $data) && $data['ip_forward'] !== null && $data['ip_forward'] != $server->ip_forward) {
        $server->ip_forward = (bool) $data['ip_forward'];
        $dirty = true;
    }

    // ---- NEW: WireGuard + endpoint/DNS facts ----

    // public IP / endpoint host
    if (!empty($data['public_ip']) && $data['public_ip'] !== $server->wg_endpoint_host) {
        $server->wg_endpoint_host = $data['public_ip'];
        $dirty = true;
    }

    // wg public key
    if (!empty($data['wg_public_key']) && $data['wg_public_key'] !== $server->wg_public_key) {
        $server->wg_public_key = $data['wg_public_key'];
        $dirty = true;
    }

    // wg port
    if (array_key_exists('wg_port', $data) && $data['wg_port'] !== null && (int) $data['wg_port'] !== (int) $server->wg_port) {
        $server->wg_port = (int) $data['wg_port'];
        $dirty = true;
    }

    // wg subnet (10.66.66.0/24 etc.)
    if (!empty($data['wg_subnet']) && $data['wg_subnet'] !== $server->wg_subnet) {
        $server->wg_subnet = $data['wg_subnet'];
        $dirty = true;
    }

    // DNS for WG/OpenVPN (your internal resolver IP)
    if (!empty($data['dns']) && $data['dns'] !== $server->dns) {
        $server->dns = $data['dns'];
        $dirty = true;
    }

    if ($dirty) {
        $server->saveQuietly();
    }

    Log::channel('vpn')->info("📡 DeployFacts #{$server->id}", array_merge(
        $data,
        [
            'wg_endpoint_host' => $server->wg_endpoint_host,
            'wg_public_key_set' => $server->wg_public_key ? true : false,
            'wg_port_final'     => $server->wg_port,
        ]
    ));

    return response()->json(['ok' => true]);
}

    public function event(Request $request, $server)
    {
        $data = $request->validate([
            'status'  => 'required|string|in:queued,running,succeeded,failed,info,mgmt',
            'message' => 'required|string',
        ]);

        /** @var \App\Models\VpnServer $vpn */
        $vpn = \App\Models\VpnServer::findOrFail($server);

        if ($data['status'] === 'mgmt') {
    $raw = $data['message'];
    $ts  = now()->toIso8601String();

    // Try to parse "clients=2 [alice,bob]" or "[mgmt] 2 online: [alice,bob]"
    $clients = 0; $cnList = '';
    if (preg_match('/clients=(\d+)\s*\[([^\]]*)\]/i', $raw, $m)) {
        $clients = (int) $m[1];
        $cnList  = trim($m[2] ?? '');
    } elseif (preg_match('/\[mgmt\]\s*(\d+)\s*online:\s*\[([^\]]*)\]/i', $raw, $m)) {
        $clients = (int) $m[1];
        $cnList  = trim($m[2] ?? '');
    }

    $cnList = preg_replace('/\s+/', '', $cnList ?? '');
    if ($cnList === '[]') $cnList = '';

    // (optional) keep these for quick counters/logs
    cache()->put("servers:{$vpn->id}:clients",        $clients, 300);
    cache()->put("servers:{$vpn->id}:cn_list",        $cnList, 300);
    cache()->put("servers:{$vpn->id}:mgmt_last_seen", $ts, 300);

    $lastKey   = "servers:{$vpn->id}:mgmt_last_log";
    $lastState = cache()->get("servers:{$vpn->id}:mgmt_state");
    $state     = "{$clients}|{$cnList}";
    $shouldLog = $state !== $lastState || !cache()->has($lastKey);
    if ($shouldLog) {
        \Log::channel('vpn')->debug("[mgmt] {$clients} online: [{$cnList}]");
        cache()->put($lastKey, 1, 60);
        cache()->put("servers:{$vpn->id}:mgmt_state", $state, 300);
    }

    // Build a minimal users[] array for the JSON controller
    $users = [];
    if ($cnList !== '') {
        foreach (array_filter(explode(',', trim($cnList, '[]'))) as $name) {
            $name = trim($name);
            if ($name !== '') $users[] = ['username' => $name];
        }
    }

    // Forward to the new JSON handler (this will sync DB and broadcast rich payload)
    $forward = \Illuminate\Http\Request::create('', 'POST', [
        'status'  => 'mgmt',
        'message' => $raw,
        'ts'      => $ts,
        'users'   => $users,
        // 'clients' => $clients, // optional; store() derives count from users
    ]);

    return app(\App\Http\Controllers\Api\DeployEventController::class)
        ->store($forward, $vpn);
}

        // non-mgmt events
        $vpn->deployment_status = $data['status'] === 'info'
            ? $vpn->deployment_status
            : $data['status'];

        $vpn->appendLog(sprintf('[%s] %s: %s',
            now()->toDateTimeString(),
            strtoupper($data['status']),
            $data['message']
        ));
        $vpn->save();

        Log::channel('vpn')->info("📡 DeployEvent #{$vpn->id}", $data);

        return response()->json(['ok' => true]);
    }

    public function log(Request $request, VpnServer $server)
    {
        $data = $request->validate([
            'line' => 'required|string',
        ]);

        $line = rtrim($data['line'], "\r\n");
        if ($line !== '') {
            $server->appendLog($line);
        }

        try {
            broadcast_event('deploy.log', [
                'server_id' => $server->id,
                'line'      => $line,
            ]);
        } catch (\Throwable $e) {}

        Log::channel('vpn')->debug("[deploy.log] #{$server->id} {$line}");

        return response()->json(['ok' => true]);
    }

    public function pushMgmtSnapshot(Request $req, VpnServer $server)
    {
        $data = $req->validate([
            'online_count' => 'required|integer|min:0',
            'online_users' => 'array',
            'source'       => 'nullable|string',
            'ts'           => 'nullable|string',
        ]);

        $server->online_count = (int) $data['online_count'];
        if (isset($data['online_users'])) {
            $server->online_users = array_values(array_filter($data['online_users'], 'strlen'));
        }
        $server->last_mgmt_at = Carbon::parse($data['ts'] ?? now());
        $server->save();

        ServerMgmtUpdated::dispatch($server);

        Log::channel('vpn')->debug("[pushMgmtSnapshot] #{$server->id} online={$server->online_count}");

        return response()->json(['ok' => true]);
    }

    public function pushMgmt(Request $request, VpnServer $server)
    {
        $payload = $request->validate([
            'uptime'  => 'nullable|string',
            'cpu'     => 'nullable|string',
            'mem'     => 'nullable|array',
            'mem.total' => 'nullable|string',
            'mem.used'  => 'nullable|string',
            'mem.free'  => 'nullable|string',
            'iface'   => 'nullable|string',
            'rate'    => 'nullable|array',
            'rate.up_mbps'   => 'nullable|numeric',
            'rate.down_mbps' => 'nullable|numeric',
            'clients' => 'nullable|array',
            'clients.*.username'        => 'nullable|string',
            'clients.*.real_ip'         => 'nullable|string',
            'clients.*.virtual_ip'      => 'nullable|string',
            'clients.*.bytes_rx'        => 'nullable|integer',
            'clients.*.bytes_tx'        => 'nullable|integer',
            'clients.*.connected_since' => 'nullable|integer',
        ]);

        Cache::put("vpn:mgmt:{$server->id}", $payload, now()->addSeconds(30));

        try {
            broadcast_event('vpn.mgmt', [
                'server_id' => $server->id,
                'payload'   => $payload,
            ]);
        } catch (\Throwable $e) {}

        Log::channel('vpn')->debug("[pushMgmt] #{$server->id}", $payload);

        return response()->json(['ok' => true]);
    }

    public function authFile(VpnServer $server)
    {
        $path = $this->authPath($server->id);

        if (!Storage::disk('local')->exists($path)) {
            return response()->json(['error' => 'No auth file'], Response::HTTP_NOT_FOUND);
        }

        return response(
            Storage::disk('local')->get($path),
            200,
            ['Content-Type' => 'text/plain; charset=utf-8']
        );
    }

    public function uploadAuthFile(Request $request, VpnServer $server)
    {
        $request->validate([
            'file' => 'required|file',
        ]);

        $contents = file_get_contents($request->file('file')->getRealPath());
        Storage::disk('local')->put($this->authPath($server->id), $contents);

        $server->appendLog('[panel] Updated mirrored auth file');
        $server->save();

        try {
            broadcast_event('deploy.authfile', [
                'server_id'  => $server->id,
                'updated_at' => now()->toIso8601String(),
            ]);
        } catch (\Throwable $e) {}

        Log::channel('vpn')->debug("[authFile] Updated #{$server->id}");

        return response()->json(['ok' => true]);
    }

    private function authPath($serverId): string
    {
        return "servers/{$serverId}/openvpn/psw-file";
    }
}