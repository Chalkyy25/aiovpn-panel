<?php

namespace App\Http\Controllers;

use App\Models\VpnServer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class DeployApiController extends Controller
{
    /**
     * POST /api/servers/{server}/deploy/events
     * Body: { "status": "running|succeeded|failed|info", "message": "text" }
     */
    
    public function facts(Request $req, \App\Models\VpnServer $server)
{
    $data = $req->validate([
        'iface'      => 'nullable|string',
        'mgmt_port'  => 'nullable|integer',
        'vpn_port'   => 'nullable|integer',
        'proto'      => 'nullable|string',
        'ip_forward' => 'nullable|boolean',
    ]);
    $server->fill([
        'iface' => $data['iface'] ?? $server->iface,
        'port'  => $data['vpn_port'] ?? $server->port,
        'protocol' => $data['proto'] ?? $server->protocol,
    ])->save();

    Log::info("📡 DeployFacts #{$server->id}", $data);
    return response()->json(['ok' => true]);
}
    
    public function event(Request $request, $server)
    {
        $data = $request->validate([
            'status'  => 'required|string|in:queued,running,succeeded,failed,info',
            'message' => 'required|string',
        ]);

        /** @var VpnServer $vpn */
        $vpn = VpnServer::findOrFail($server);

        // Update DB status + append to log
        $vpn->deployment_status = $data['status'] === 'info' ? $vpn->deployment_status : $data['status'];
        $vpn->appendLog(sprintf('[%s] %s: %s', now()->toDateTimeString(), strtoupper($data['status']), $data['message']));
        $vpn->save();

        Log::info("📡 DeployEvent #{$vpn->id}", $data);

        return response()->json(['ok' => true]);
    }

    /**
     * POST /api/servers/{server}/deploy/logs
     * Body: { "line": "text" }
     */
    public function log(Request $request, $server)
    {
        $data = $request->validate([
            'line' => 'required|string',
        ]);

        /** @var VpnServer $vpn */
        $vpn = VpnServer::findOrFail($server);
        $line = rtrim($data['line'], "\r\n");
        if ($line !== '') {
            $vpn->appendLog($line);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * GET /api/servers/{server}/authfile
     * Returns the mirrored OpenVPN password file (if previously uploaded).
     */
    public function authFile($server)
    {
        $path = $this->authPath($server);

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
    public function uploadAuthFile(Request $request, $server)
    {
        $request->validate([
            'file' => 'required|file',
        ]);

        $contents = file_get_contents($request->file('file')->getRealPath());
        Storage::disk('local')->put($this->authPath($server), $contents);

        /** @var VpnServer $vpn */
        $vpn = VpnServer::findOrFail($server);
        $vpn->appendLog('[panel] Updated mirrored auth file');
        $vpn->save();

        return response()->json(['ok' => true]);
    }

    private function authPath($serverId): string
    {
        return "servers/{$serverId}/openvpn/psw-file";
    }
}