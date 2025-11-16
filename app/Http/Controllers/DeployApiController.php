<?php

namespace App\Http\Controllers;

use App\Models\VpnServer;
use App\Events\ServerMgmtUpdated;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class DeployApiController extends Controller
{
    /**
     * POST /api/servers/{server}/deploy/facts
     * Called by deploy script to push facts (WG + OpenVPN) into the DB.
     */
    public function facts(Request $req, VpnServer $server)
    {
        $data = $req->validate([
            // basic facts from script
            'iface'              => 'nullable|string',
            'mgmt_port'          => 'nullable|integer',
            'mgmt_tcp_port'      => 'nullable|integer',
            'vpn_port'           => 'nullable|integer',
            // script sends "wireguard+openvpn-stealth" etc
            'proto'              => 'nullable|string',

            // WireGuard
            'wg_public_key'      => 'nullable|string',
            'wg_port'            => 'nullable|integer',
            'wg_subnet'          => 'nullable|string',
            'wg_endpoint_host'   => 'nullable|string',

            // OpenVPN / misc
            'ovpn_endpoint_host' => 'nullable|string',
            'ovpn_udp_port'      => 'nullable|integer',
            'tcp_stealth_enabled'=> 'nullable',
            'tcp_port'           => 'nullable|integer',
            'tcp_subnet'         => 'nullable|string',
            'status_udp'         => 'nullable|string',
            'status_tcp'         => 'nullable|string',

            // generic extras
            'ip_forward'         => 'nullable',
            'dns'                => 'nullable|string',
        ]);

        // Only touch known DB columns; keep existing values if a key is missing
        $server->fill([
            'iface'            => $data['iface']            ?? $server->iface,
            'mgmt_port'        => $data['mgmt_port']        ?? $server->mgmt_port,
            'tcp_mgmt_port'    => $data['mgmt_tcp_port']    ?? $server->tcp_mgmt_port,
            'port'             => $data['vpn_port']         ?? $server->port,
            'protocol'         => $data['proto']            ?? $server->protocol,

            // WireGuard
            'wg_public_key'    => $data['wg_public_key']    ?? $server->wg_public_key,
            'wg_port'          => $data['wg_port']          ?? $server->wg_port,
            'wg_subnet'        => $data['wg_subnet']        ?? $server->wg_subnet,
            'wg_endpoint_host' => $data['wg_endpoint_host'] ?? $server->wg_endpoint_host,

            // Store the UDP status log path; useful for dashboards
            'status_log_path'  => $data['status_udp']       ?? $server->status_log_path,
        ]);

        // Prefer internal resolver if script/provisioning didn’t set dns
        if (!empty($data['dns'])) {
            $server->dns = $data['dns'];
        } elseif (empty($server->dns)) {
            $server->dns = '10.66.66.1';
        }

        $server->saveQuietly();

        Log::channel('vpn')->info("📡 DeployFacts #{$server->id}", array_merge(
            $data,
            [
                'wg_endpoint_host'  => $server->wg_endpoint_host,
                'wg_public_key_set' => (bool) $server->wg_public_key,
                'wg_port_final'     => $server->wg_port,
            ]
        ));

        return response()->json(['ok' => true]);
    }

    /**
     * POST /api/servers/{server}/deploy/events
     * Simple string-based events from the deploy script.
     * Special case: status="mgmt" is forwarded to the JSON DeployEventController.
     */
    public function event(Request $request, $server)
    {
        $data = $request->validate([
            'status'  => 'required|string|in:queued,running,succeeded,failed,info,mgmt',
            'message' => 'required|string',
        ]);

        /** @var VpnServer $vpn */
        $vpn = VpnServer::findOrFail($server);

        // Special mgmt line: forward into the richer JSON handler
        if ($data['status'] === 'mgmt') {
            $raw = $data['message'];
            $ts  = now()->toIso8601String();

            // Try to parse "clients=2 [alice,bob]" or "[mgmt] 2 online: [alice,bob]"
            $clients = 0;
            $cnList  = '';

            if (preg_match('/clients=(\d+)\s*\[([^\]]*)\]/i', $raw, $m)) {
                $clients = (int) $m[1];
                $cnList  = trim($m[2] ?? '');
            } elseif (preg_match('/\[mgmt\]\s*(\d+)\s*online:\s*\[([^\]]*)\]/i', $raw, $m)) {
                $clients = (int) $m[1];
                $cnList  = trim($m[2] ?? '');
            }

            $cnList = preg_replace('/\s+/', '', $cnList ?? '');
            if ($cnList === '[]') {
                $cnList = '';
            }

            // quick cache counters
            cache()->put("servers:{$vpn->id}:clients",        $clients, 300);
            cache()->put("servers:{$vpn->id}:cn_list",        $cnList, 300);
            cache()->put("servers:{$vpn->id}:mgmt_last_seen", $ts, 300);

            $lastKey   = "servers:{$vpn->id}:mgmt_last_log";
            $lastState = cache()->get("servers:{$vpn->id}:mgmt_state");
            $state     = "{$clients}|{$cnList}";

            $shouldLog = $state !== $lastState || !cache()->has($lastKey);
            if ($shouldLog) {
                Log::channel('vpn')->debug("[mgmt] {$clients} online: [{$cnList}]");
                cache()->put($lastKey, 1, 60);
                cache()->put("servers:{$vpn->id}:mgmt_state", $state, 300);
            }

            // Build minimal users[] for the JSON controller
            $users = [];
            if ($cnList !== '') {
                foreach (array_filter(explode(',', trim($cnList, '[]'))) as $name) {
                    $name = trim($name);
                    if ($name !== '') {
                        $users[] = ['username' => $name];
                    }
                }
            }

            // Forward into Api\DeployEventController@store
            $forward = Request::create('', 'POST', [
                'status'  => 'mgmt',
                'message' => $raw,
                'ts'      => $ts,
                'users'   => $users,
            ]);

            return app(\App\Http\Controllers\Api\DeployEventController::class)
                ->store($forward, $vpn);
        }

        // Non-mgmt events
        $vpn->deployment_status = $data['status'] === 'info'
            ? $vpn->deployment_status
            : $data['status'];

        $vpn->appendLog(sprintf(
            '[%s] %s: %s',
            now()->toDateTimeString(),
            strtoupper($data['status']),
            $data['message']
        ));
        $vpn->save();

        Log::channel('vpn')->info("📡 DeployEvent #{$vpn->id}", $data);

        return response()->json(['ok' => true]);
    }

    /**
     * POST /api/servers/{server}/deploy/logs
     * One-line log streaming from the deploy script.
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
            // ignore broadcast errors
        }

        Log::channel('vpn')->debug("[deploy.log] #{$server->id} {$line}");

        return response()->json(['ok' => true]);
    }

    /**
     * POST /api/servers/{server}/mgmt/snapshot
     * Simple “count + usernames” snapshot for dashboards.
     */
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

    /**
     * POST /api/servers/{server}/mgmt
     * Rich JSON mgmt payload (uptime, cpu, mem, per-client stats, etc).
     */
    public function pushMgmt(Request $request, VpnServer $server)
    {
        $payload = $request->validate([
            'uptime'                 => 'nullable|string',
            'cpu'                    => 'nullable|string',
            'mem'                    => 'nullable|array',
            'mem.total'              => 'nullable|string',
            'mem.used'               => 'nullable|string',
            'mem.free'               => 'nullable|string',
            'iface'                  => 'nullable|string',
            'rate'                   => 'nullable|array',
            'rate.up_mbps'           => 'nullable|numeric',
            'rate.down_mbps'         => 'nullable|numeric',
            'clients'                => 'nullable|array',
            'clients.*.username'     => 'nullable|string',
            'clients.*.real_ip'      => 'nullable|string',
            'clients.*.virtual_ip'   => 'nullable|string',
            'clients.*.bytes_rx'     => 'nullable|integer',
            'clients.*.bytes_tx'     => 'nullable|integer',
            'clients.*.connected_since' => 'nullable|integer',
        ]);

        Cache::put("vpn:mgmt:{$server->id}", $payload, now()->addSeconds(30));

        try {
            broadcast_event('vpn.mgmt', [
                'server_id' => $server->id,
                'payload'   => $payload,
            ]);
        } catch (\Throwable $e) {
            // ignore broadcast errors
        }

        Log::channel('vpn')->debug("[pushMgmt] #{$server->id}", $payload);

        return response()->json(['ok' => true]);
    }

    /**
     * GET /api/servers/{server}/authfile
     * Mirror of the OpenVPN psw-file stored on panel side.
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
     * POST /api/servers/{server}/authfile
     * Upload a fresh copy of OpenVPN psw-file from the panel to local storage.
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
                'server_id'  => $server->id,
                'updated_at' => now()->toIso8601String(),
            ]);
        } catch (\Throwable $e) {
            // ignore broadcast errors
        }

        Log::channel('vpn')->debug("[authFile] Updated #{$server->id}");

        return response()->json(['ok' => true]);
    }

    private function authPath($serverId): string
    {
        return "servers/{$serverId}/openvpn/psw-file";
    }
}