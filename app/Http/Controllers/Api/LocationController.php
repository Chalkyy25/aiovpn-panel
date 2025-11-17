// app/Http/Controllers/Api/LocationController.php
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

            $countryName = $first->country_name;
            $label = $first->display_location;

            return [
                'country_code' => $code ?: null,
                'country_name' => $countryName,
                'city'         => $city ?: null,
                'label'        => $label,
                'servers'      => $group->map(function (VpnServer $s) {
                    return [
                        'id'        => (int) $s->id,
                        'name'      => $s->name,
                        'ip'        => $s->ip_address,
                        'protocol'  => $s->protocol,
                        'transport' => $s->displayTransport(),
                        'port'      => $s->displayPort(),
                        'tags'      => $s->tags,
                    ];
                })->values(),
            ];
        })->values();

        return response()->json([
            'data' => $locations,
        ]);
    }
}

