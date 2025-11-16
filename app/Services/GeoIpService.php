<?php

namespace App\Services;

use App\Models\VpnServer;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeoIpService
{
    /**
     * Lookup and store GeoIP for a server.
     *
     * Returns true if we actually changed something,
     * false if nothing was updated (already set or lookup failed).
     */
    public function updateServer(VpnServer $server): bool
    {
        if (!$server->ip_address) {
            Log::warning("GeoIP: server #{$server->id} has no ip_address");
            return false;
        }

        // If both are already set, don't hammer the API
        if (!empty($server->country_code) && !empty($server->city)) {
            Log::info("GeoIP: already set for #{$server->id} ({$server->city}, {$server->country_code})");
            return false;
        }

        try {
            $url = sprintf(
                'http://ip-api.com/json/%s?fields=status,country,countryCode,city,regionName,message',
                $server->ip_address
            );

            $res = Http::timeout(5)->get($url);

            if (!$res->ok()) {
                Log::warning("GeoIP: HTTP error for {$server->ip_address} (status {$res->status()})");
                return false;
            }

            $json   = $res->json();
            $status = $json['status'] ?? null;

            if ($status !== 'success') {
                Log::warning("GeoIP: API failure for {$server->ip_address} (" . ($json['message'] ?? 'no message') . ")");
                return false;
            }

            $countryCode = $json['countryCode'] ?? null;
            $city        = $json['city'] ?: ($json['regionName'] ?? null);

            if (!$countryCode && !$city) {
                Log::warning("GeoIP: empty data for {$server->ip_address}");
                return false;
            }

            $server->country_code = $countryCode ?: $server->country_code;
            $server->city         = $city ?: $server->city;
            $server->saveQuietly();

            Log::info("🌍 GeoIP updated #{$server->id} to {$server->city}, {$server->country_code}");

            return true;
        } catch (\Throwable $e) {
            Log::warning(
                "GeoIP: exception for #{$server->id} ({$server->ip_address}): " . $e->getMessage()
            );
            return false;
        }
    }
}