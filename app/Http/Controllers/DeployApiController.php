<?php

namespace App\Http\Controllers;

use App\Models\VpnServer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

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
    public function event(Request $request, VpnServer $server)
    {
        $data = $request->validate([
            'status'  => 'required|string|in:queued,running,succeeded,failed,info',
            'message' => 'required|string',
        ]);

        // Keep status unless this is an informational ping
        if ($data['status'] !== 'info') {
            $server->deployment_status = $data['status'];
        }

        $server->appendLog(sprintf(
            '[%s] %s: %s',
            now()->toDateTimeString(),
            strtoupper($data['status']),
            $data['message']
        ));
        $server->save();

        Log::info("📡 DeployEvent #{$server->id}", $data);

        // Light broadcast hook your dashboard can listen to (optional)
        try {
            broadcast_event('deploy.event', [
                'server_id' => $server->id,
                'status'    => $data['status'],
                'message'   => $data['message'],
            ]);
        } catch (\Throwable $e) {
            // broadcasting is optional; don’t fail the request
        }

        return response()->json(['ok' => true]);
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