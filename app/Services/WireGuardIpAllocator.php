<?php

namespace App\Services;

use App\Models\VpnUser;

class WireGuardIpAllocator
{
    public static function next(string $base = '10.66.66.'): string
    {
        $used = VpnUser::query()
            ->whereNotNull('wireguard_address')
            ->pluck('wireguard_address')
            ->map(function (string $cidr): int {
                $ip = str_replace('/32', '', $cidr);
                return (int) substr($ip, strrpos($ip, '.') + 1);
            })
            ->filter(fn (int $n) => $n >= 2 && $n <= 254)
            ->values()
            ->all();

        for ($i = 2; $i <= 254; $i++) {
            if (! in_array($i, $used, true)) {
                return $base . $i . '/32';
            }
        }

        throw new \RuntimeException('No available WireGuard IP addresses left.');
    }
}