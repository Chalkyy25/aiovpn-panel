<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\VpnServer;
use App\Services\VpnConfigBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class GenericStealthConfigController extends Controller
{
    public function __construct(
        private VpnConfigBuilder $configBuilder
    ) {}

    /**
     * Get list of available stealth servers
     */
    public function servers(Request $request): JsonResponse
    {
        $servers = VpnServer::where('status', 'active')
            ->select(['id', 'name', 'hostname', 'country', 'city', 'server_type'])
            ->orderBy('country')
            ->orderBy('name')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $servers->map(function ($server) {
                return [
                    'id' => $server->id,
                    'name' => $server->name,
                    'location' => $server->city ? "{$server->city}, {$server->country}" : $server->country,
                    'type' => $server->server_type,
                    'hostname' => $server->hostname,
                ];
            })
        ]);
    }

    /**
     * Generate stealth config for specific server
     */
    public function config(Request $request, int $serverId)
    {
        $server = VpnServer::where('id', $serverId)
            ->where('status', 'active')
            ->first();

        if (!$server) {
            return response()->json([
                'status' => 'error',
                'message' => 'Server not found or inactive'
            ], 404);
        }

        try {
            $config = $this->configBuilder->generateGenericStealthConfig($server);
            
            $safeName = preg_replace('/[^\w\-]+/u', '_', $server->name);
            $filename = "aio_stealth_{$safeName}.ovpn";

            return response($config, 200, [
                'Content-Type' => 'application/x-openvpn-profile',
                'Content-Disposition' => "attachment; filename=\"{$filename}\"",
                'Cache-Control' => 'no-cache, no-store, must-revalidate'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to generate config: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get config info without downloading
     */
    public function configInfo(Request $request, int $serverId): JsonResponse
    {
        $server = VpnServer::where('id', $serverId)
            ->where('status', 'active')
            ->first();

        if (!$server) {
            return response()->json([
                'status' => 'error',
                'message' => 'Server not found or inactive'
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'server_id' => $server->id,
                'server_name' => $server->name,
                'location' => $server->city ? "{$server->city}, {$server->country}" : $server->country,
                'hostname' => $server->hostname,
                'protocol' => 'TCP',
                'port' => 443,
                'type' => 'stealth',
                'cipher' => 'AES-128-GCM',
                'auth' => 'SHA256',
                'notes' => 'ISP bypass optimized - appears as HTTPS traffic'
            ]
        ]);
    }
}