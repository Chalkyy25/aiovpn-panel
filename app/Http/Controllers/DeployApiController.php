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

            cache()->put("servers:{$vpn->id}:clients", $clients, 300);
            cache()->put("servers:{$vpn->id}:cn_list", $cnList, 300);
            cache()->put("servers:{$vpn->id}:mgmt_last_seen", $ts, 300);

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
                // 👇 quieter: goes only into vpn.log
                Log::channel('vpn')->debug("[mgmt] {$clients} online: [{$cnList}]");
                cache()->put($lastKey, 1, 60);
            }

            event(new ServerMgmtEvent(
                serverId: $vpn->id,
                ts: $ts,
                clients: $clients,
                cnList: $cnList,
                raw: $raw
            ));

            return response()->json(['ok' => true]);
        }

        // Default path for non-mgmt events
        $vpn->deployment_status = $data['status'] === 'info'
            ? $vpn->deployment_status
            : $data['status'];

        $vpn->appendLog(sprintf('[%s] %s: %s',
            now()->toDateTimeString(),
            strtoupper($data['status']),
            $data['message']
        ));
        $vpn->save();

        Log::info("📡 DeployEvent #{$vpn->id}", $data);

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

        // 👇 keep these in vpn.log only
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

        // quieter log
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

        // quieter log
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