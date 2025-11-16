<?php

namespace App\Support;

use App\Models\VpnServer;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeoIpUpdater
{
    /**
     * Fill country_code + city for a server using its public IP.
     */
    public static function update(VpnServer $server): void
    {
        $ip = $server->ip_address;
        if (!$ip) {
            Log::warning("🌍 GeoIP: server #{$server->id} has no IP, skipping");
            return;
        }

        // If already populated, skip – this keeps manual overrides too
        if (!empty($server->country_code) && !empty($server->city)) {
            Log::info("🌍 GeoIP: server #{$server->id} already has {$server->country_code}/{$server->city}, skipping");
            return;
        }

        try {
            // Simple free lookup (ip-api.com) – good enough for this use
            $resp = Http::timeout(5)->get("http://ip-api.com/json/{$ip}", [
                'fields' => 'status,country,countryCode,city',
            ]);

            if (!$resp->ok()) {
                Log::warning("🌍 GeoIP: HTTP error for {$ip} (server #{$server->id}): {$resp->status()}");
                return;
            }

            $data = $resp->json();
            if (!is_array($data) || ($data['status'] ?? '') !== 'success') {
                Log::warning("🌍 GeoIP: lookup failed for {$ip} (server #{$server->id}): " . json_encode($data));
                return;
            }

            $countryCode = $data['countryCode'] ?? null;
            $city        = $data['city'] ?? null;

            if (empty($countryCode) && empty($city)) {
                Log::warning("🌍 GeoIP: empty result for {$ip} (server #{$server->id})");
                return;
            }

            $server->country_code = $countryCode ?: $server->country_code;
            $server->city         = $city ?: $server->city;
            $server->saveQuietly();

            Log::info("🌍 GeoIP: server #{$server->id} → {$server->country_code}/{$server->city}");
        } catch (\Throwable $e) {
            Log::error("🌍 GeoIP: exception for {$ip} (server #{$server->id}): " . $e->getMessage());
        }
    }
}