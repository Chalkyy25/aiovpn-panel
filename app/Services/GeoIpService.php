<?php

namespace App\Services;

use App\Models\VpnServer;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeoIpService
{
    // Free plan: HTTP only
    protected string $endpoint = 'http://ip-api.com/json/';

    /**
     * Low-level lookup. Returns array or null on failure.
     */
    public function lookupIp(string $ip): ?array
    {
        try {
            $resp = Http::timeout(5)
                ->acceptJson()
                ->get($this->endpoint . $ip, [
                    'fields' => 'status,country,countryCode,city,regionName,message',
                ]);

            if (! $resp->ok()) {
                Log::warning("GeoIP: HTTP error for {$ip}: {$resp->status()}");
                return null;
            }

            $data = $resp->json() ?? [];
            if (($data['status'] ?? 'fail') !== 'success') {
                Log::warning("GeoIP: API fail for {$ip}: " . ($data['message'] ?? 'no message'));
                return null;
            }

            Log::info("GeoIP: {$ip} → " . json_encode($data));

            return $data;
        } catch (\Throwable $e) {
            Log::warning("GeoIP: exception for {$ip}: {$e->getMessage()}");
            return null;
        }
    }

    /**
     * Update a single server model. Returns true if anything changed.
     */
    public function updateServer(VpnServer $server): bool
    {
        // If both already set, don’t hammer the API
        if ($server->country_code && $server->city) {
            return false;
        }

        $ip = $server->ip_address;
        if (! $ip) {
            Log::warning("GeoIP: server #{$server->id} has no IP");
            return false;
        }

        $data = $this->lookupIp($ip);
        if (! $data) {
            return false;
        }

        $dirty = false;

        if (! $server->country_code && ! empty($data['countryCode'])) {
            $server->country_code = $data['countryCode'];
            $dirty = true;
        }

        if (! $server->city && ! empty($data['city'])) {
            $server->city = $data['city'];
            $dirty = true;
        }

        if ($dirty) {
            $server->save();
            Log::info("GeoIP: updated server #{$server->id} → {$server->country_code} / {$server->city}");
        }

        return $dirty;
    }

    /**
     * Optional helper: backfill all servers with missing geo.
     */
    public function backfillAll(): int
    {
        $count = 0;

        VpnServer::whereNull('country_code')
            ->orWhereNull('city')
            ->chunkById(50, function ($servers) use (&$count) {
                foreach ($servers as $server) {
                    if ($this->updateServer($server)) {
                        $count++;
                    }
                }
            });

        Log::info("GeoIP: backfill complete, updated {$count} server(s).");

        return $count;
    }
}