<?php

namespace App\Services;

use App\Models\VpnServer;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeoIpService
{
    /**
     * Look up IP -> country_code + city via external API.
     */
    public function lookup(string $ip): ?array
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return null;
        }

        try {
            // Example provider: ipapi.co
            $response = Http::timeout(5)->get("https://ipapi.co/{$ip}/json/");

            if (!$response->ok()) {
                return null;
            }

            $data = $response->json();

            if (!$data || (empty($data['country']) && empty($data['city']))) {
                return null;
            }

            return [
                'country_code' => !empty($data['country'])
                    ? strtoupper($data['country'])  // e.g. "DE"
                    : null,
                'city' => $data['city'] ?? null,   // e.g. "Frankfurt"
            ];
        } catch (\Throwable $e) {
            Log::warning('GeoIp lookup failed', [
                'ip'      => $ip,
                'message' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Update a VpnServer’s country_code + city from its ip_address.
     * Returns true if anything changed.
     */
    public function updateServer(VpnServer $server): bool
    {
        if (!$server->ip_address) {
            return false;
        }

        $geo = $this->lookup($server->ip_address);
        if (!$geo) {
            return false;
        }

        $changed = false;

        if (!empty($geo['country_code']) && $server->country_code !== $geo['country_code']) {
            $server->country_code = $geo['country_code'];
            $changed = true;
        }

        if (!empty($geo['city']) && $server->city !== $geo['city']) {
            $server->city = $geo['city'];
            $changed = true;
        }

        if ($changed) {
            $server->save();
        }

        return $changed;
    }
}