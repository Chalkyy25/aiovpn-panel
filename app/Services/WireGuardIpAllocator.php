<?php

namespace App\Services;

use App\Models\VpnUser;

class WireGuardIpAllocator
{
    public static function next(string $base = '10.66.66.'): string
    {
        $usedSuffixes = VpnUser::query()
            ->whereNotNull('wireguard_address')
            ->selectRaw("CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(wireguard_address, '/', 1), '.', -1) AS UNSIGNED) as suffix")
            ->pluck('suffix')
            ->map(fn ($n) => (int) $n)
            ->filter(fn (int $n) => $n >= 2 && $n <= 254)
            ->unique()
            ->values()
            ->all();

        for ($i = 2; $i <= 254; $i++) {
            if (! in_array($i, $usedSuffixes, true)) {
                return $base . $i . '/32';
            }
        }

        throw new \RuntimeException("No available WireGuard IP addresses left in {$base}0/24.");
    }
}