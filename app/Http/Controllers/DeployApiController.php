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
    /**
     * POST /api/servers/{server}/deploy/facts
     * Body: { iface?, mgmt_port?, vpn_port?, proto?, ip_forward? }
     */
    public function facts(Request $req, VpnServer $server)
    {
        $data = $req->validate([
            'iface'      => 'nullable|string',
            'mgmt_port'  => 'nullable|integer',
            'vpn_port'   => 'nullable|integer',
            'proto'      => 'nullable|string|in:udp,tcp,openvpn,wireguard',
            'ip_forward' => 'nullable|boolean',
        ]);

        $server->fill([
            'iface'    => $data['iface'] ?? $server->iface,
            'port'     => $data['vpn_port'] ?? $server->port,
            'protocol' => $data['proto'] ?? $server->protocol,
        ])->save();

        Log::info("📡 DeployFacts #{$server->id}", $data);
        return response()->json(['ok' => true]);
    }

    /**
     * POST /api/servers/{server}/deploy/events
     * Body: { "status": "queued|running|succeeded|failed|info", "message": "text" }
     */
    public function event(Request $request, $server)
{
    $data = $request->validate([
        'status'  => 'required|string|in:queued,running,succeeded,failed,info,mgmt',
        'message' => 'required|string',
    ]);

    /** @var \App\Models\VpnServer $vpn */
    $vpn = \App\Models\VpnServer::findOrFail($server);

    // ---- Special handling for management snapshots ----
    if ($data['status'] === 'mgmt') {
        $raw = $data['message'];
        $ts  = now()->toIso8601String();

        // Parse both formats we’ve used:
        //  1) "ts=... source=... clients=1 [name1,name2]"
        //  2) "[mgmt] 1 online: [name1, name2]"
        $clients = 0; $cnList = '';

        if (preg_match('/clients=(\d+)\s*\[([^\]]*)\]/i', $raw, $m)) {
            $clients = (int) $m[1];
            $cnList  = trim($m[2] ?? '');
        } elseif (preg_match('/\[mgmt\]\s*(\d+)\s*online:\s*\[([^\]]*)\]/i', $raw, $m)) {
            $clients = (int) $m[1];
            $cnList  = trim($m[2] ?? '');
        }

        // Normalise comma list: allow empty -> ''
        $cnList = preg_replace('/\s+/', '', $cnList ?? '');
        if ($cnList === '[]') $cnList = '';
        // Cache a 5‑minute TTL so Livewire/pages can read a baseline
        cache()->put("servers:{$vpn->id}:clients", $clients, 300);
        cache()->put("servers:{$vpn->id}:cn_list", $cnList, 300);
        cache()->put("servers:{$vpn->id}:mgmt_last_seen", $ts, 300);

        // Throttle log noise: only log mgmt when value changes or once per 60s
        $lastKey   = "servers:{$vpn->id}:mgmt_last_log";
        $lastState = cache()->get("servers:{$vpn->id}:mgmt_state");
        $state     = "{$clients}|{$cnList}";
        $shouldLog = false;

        if ($state !== $lastState) {
            $shouldLog = true;
            cache()->put("servers:{$vpn->id}:mgmt_state", $state, 300);
        } elseif (!cache()->has($lastKey)) {
            $shouldLog = true;
        }

        if ($shouldLog) {
            $vpn->appendLog(sprintf('[mgmt] %d online: [%s]', $clients, $cnList));
            cache()->put($lastKey, 1, 60);
        }

        // Broadcast to your private channel (ServerMgmtEvent you posted)
        event(new \App\Events\ServerMgmtEvent(
            serverId: $vpn->id,
            ts: $ts,
            clients: $clients,
            cnList: $cnList,
            raw: $raw
        ));

        return response()->json(['ok' => true]);
    }

    // ---- Default (non‑mgmt) path: preserve your current behaviour ----
    $vpn->deployment_status = $data['status'] === 'info' ? $vpn->deployment_status : $data['status'];
    $vpn->appendLog(sprintf('[%s] %s: %s', now()->toDateTimeString(), strtoupper($data['status']), $data['message']));
    $vpn->save();

    Log::info("📡 DeployEvent #{$vpn->id}", $data);

    return response()->json(['ok' => true]);
}
/** Tiny helper to regex match first group */
private function match(string $pattern, string $subject): ?string
{
    return preg_match($pattern, $subject, $m) ? ($m[1] ?? null) : null;
}

    /**
     * POST /api/servers/{server}/deploy/logs
     * Body: { "line": "text" }
     */
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
        } catch (\Throwable $e) {
            // ignore
        }

        return response()->json(['ok' => true]);
    }

    /**
     * POST /api/servers/{server}/mgmt/push
     * Body shape (example):
     * {
     *   "uptime":"23:12",
     *   "cpu":"2.3 us, 1.2 sy, 96.5 id",
     *   "mem":{"total":"1.9G","used":"450M","free":"1.4G"},
     *   "iface":"eth0",
     *   "rate":{"up_mbps":0.12,"down_mbps":1.05},
     *   "clients":[
     *     {"username":"alice","real_ip":"1.2.3.4:55555","virtual_ip":"10.8.0.2","bytes_rx":12345,"bytes_tx":67890,"connected_since":1712345678}
     *   ]
     * }
     *
     * Your deployment script can post snapshots here every N seconds or on changes.
     */
    public function pushMgmtSnapshot(Request $req, \App\Models\VpnServer $server)
{
    $data = $req->validate([
        'online_count' => 'required|integer|min:0',
        'online_users' => 'array',      // optional, names only
        'source'       => 'nullable|string',  // file|mgmt (debug)
        'ts'           => 'nullable|string',  // ISO-8601 from agent
    ]);

    $server->online_count = (int) $data['online_count'];
    if (isset($data['online_users'])) {
        $server->online_users = array_values(array_filter($data['online_users'], 'strlen'));
    }
    $server->last_mgmt_at = Carbon::parse($data['ts'] ?? now());
    $server->save();

    // Broadcast to the per-server and the dashboard channels
    ServerMgmtUpdated::dispatch($server);

    // (Optional) keep a single terse line in deployment_log if you want
    // $server->appendLog(sprintf('[mgmt] %d online', $server->online_count));

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

        // Cache for quick reads in your Livewire dashboard (TTL ~ 30s)
        Cache::put("vpn:mgmt:{$server->id}", $payload, now()->addSeconds(30));

        // Fire a broadcast so the dashboard can update live (Echo/Pusher/etc.)
        try {
            broadcast_event('vpn.mgmt', [
                'server_id' => $server->id,
                'payload'   => $payload,
            ]);
        } catch (\Throwable $e) {
            // optional
        }

        return response()->json(['ok' => true]);
    }

    /**
     * GET /api/servers/{server}/authfile
     * Returns the mirrored OpenVPN password file (text/plain) if present.
     */
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

    /**
     * POST /api/servers/{server}/authfile  (multipart/form-data: file=@psw-file)
     * Stores the OpenVPN password file mirror.
     */
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
                'server_id' => $server->id,
                'updated_at' => now()->toIso8601String(),
            ]);
        } catch (\Throwable $e) {
            // optional
        }

        return response()->json(['ok' => true]);
    }

    private function authPath($serverId): string
    {
        return "servers/{$serverId}/openvpn/psw-file";
    }
}

/**
 * Tiny helper so we can broadcast without forcing you to create an Event class
 * right now. Replace with real Events later if you prefer.
 */
if (!function_exists('broadcast_event')) {
    function broadcast_event(string $name, array $payload): void
    {
        // This expects you’ve configured broadcasting (Pusher, Ably, Redis, etc.)
        // On the JS side listen on channel: `private.vpn` or `vpn` (your choice) and event name below.
        try {
            event(new class($name, $payload) implements \Illuminate\Contracts\Broadcasting\ShouldBroadcast {
                use \Illuminate\Broadcasting\InteractsWithSockets;
                public string $eventName;
                public array $payload;
                public function __construct($eventName, $payload) { $this->eventName = $eventName; $this->payload = $payload; }
                public function broadcastOn() { return ['vpn']; }                  // public channel "vpn"
                public function broadcastAs() { return $this->eventName; }        // e.g. 'vpn.mgmt'
                public function broadcastWith() { return $this->payload; }
            });
        } catch (\Throwable $e) {
            // swallow — broadcasting is optional
        }
    }
}