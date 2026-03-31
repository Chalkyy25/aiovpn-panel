<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\VpnServer;
use Illuminate\Http\JsonResponse;

class ServerController extends Controller
{
    public function index(): JsonResponse
    {
        $servers = VpnServer::query()
            ->where('enabled', true)
            ->where('is_online', true)
            ->where('supports_wireguard', true)
            ->orderBy('name')
            ->get()
            ->map(function (VpnServer $server) {
                return [
                    'id' => $server->id,
                    'name' => $server->name,
                    'ip' => $server->wg_endpoint_host ?: $server->ip_address,
                    'port' => $server->wg_port ?: 51820,
                    'country_code' => $server->country_code,
                    'city' => $server->city,
                    'mtu' => $server->mtu ?: 1340,
                ];
            })
            ->values();

        return response()->json($servers);
    }
}
