<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\VpnServer;

class LocationController extends Controller
{
    public function index()
    {
        $servers = VpnServer::query()
            ->where('enabled', true)
            ->orderBy('country_code')
            ->orderBy('city')
            ->get();

        // Group by country_code + city so each city = one location item
        $grouped = $servers->groupBy(function (VpnServer $s) {
            return ($s->country_code ?? 'XX') . '|' . ($s->city ?? '');
        });

        $locations = $grouped->map(function ($group, $key) {
            /** @var \Illuminate\Support\Collection<int,\App\Models\VpnServer> $group */
            [$code, $city] = explode('|', $key);

            /** @var VpnServer $first */
            $first = $group->first();

            $countryName = $first->country_name;      // accessor on model
            $label       = $first->display_location;  // accessor on model

            return [
                'country_code' => $code ?: null,
                'country_name' => $countryName,
                'city'         => $city ?: null,
                'label'        => $label,
                'servers'      => $group->map(function (VpnServer $s) {
                    // Primary protocol (for backwards compatibility)
                    $primaryProtocol = $s->protocol;

                    // OpenVPN details (only if server is OpenVPN)
                    $openvpn = null;
                    if ($s->isOpenVPN()) {
                        $openvpn = [
                            'transport'   => $s->displayTransport(), // udp/tcp
                            'port'        => $s->displayPort(),      // 1194 / 443 etc.
                            'cipher'      => $s->ovpn_cipher,
                            'compression' => $s->ovpn_compression,
                        ];
                    }

                    // WireGuard details (only if server actually has WG configured)
                    $wireguard = null;
                    if ($s->hasWireGuard()) {
                        $wireguard = [
                            'endpoint'   => $s->wgEndpoint(),        // host:port
                            'host'       => $s->wg_endpoint_host,
                            'port'       => $s->wg_port ?: 51820,
                            'subnet'     => $s->wg_subnet,
                            'public_key' => $s->wg_public_key,
                            // NEVER send private key to the app
                        ];
                    }

                    // Protocol capability list
                    $protocols = [];
                    if ($openvpn) {
                        $protocols[] = 'openvpn';
                    }
                    if ($wireguard) {
                        $protocols[] = 'wireguard';
                    }

                    return [
                        'id'              => (int) $s->id,
                        'name'            => $s->name,
                        'ip'              => $s->ip_address,
                        'primaryProtocol' => $primaryProtocol,  // "openvpn" or "wireguard"
                        'protocols'       => $protocols,        // ["openvpn","wireguard"] etc.

                        // OpenVPN info (nullable)
                        'openvpn'         => $openvpn,

                        // WireGuard info (nullable)
                        'wireguard'       => $wireguard,

                        // Tags from DB (cast to array on model)
                        'tags'            => $s->tags,
                    ];
                })->values(),
            ];
        })->values();

        return response()->json([
            'data' => $locations,
        ]);
    }
}