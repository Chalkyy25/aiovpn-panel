<?php

namespace App\Services;

use Carbon\Carbon;

class OpenVpnStatusParser
{
    /**
     * Parse OpenVPN v3 (TSV) status output from mgmt or status file.
     *
     * @return array{
     *   updated_at:int,
     *   clients:array<int,array{
     *      username:string,
     *      client_ip:string,
     *      virtual_ip:string,
     *      bytes_received:int,
     *      bytes_sent:int,
     *      connected_at:?int,
     *      down_mb:float,
     *      up_mb:float,
     *      formatted_bytes:string,
     *      connected_fmt:?string,
     *      connected_human:?string,
     *      client_id:int
     *   }>,
     *   totals:array{recv:int,sent:int}
     * }
     */
    public static function parse(string $raw): array
    {
        $raw = trim($raw ?? '');
        if ($raw === '') {
            return self::emptyResult();
        }

        return self::parseV3($raw);
    }

    /* ───────────────────────────── v3 (TSV) ───────────────────────────── */

    protected static function parseV3(string $raw): array
    {
        $lines = preg_split("/\r\n|\r|\n/", $raw);
        $clients   = [];
        $totalRecv = 0;
        $totalSent = 0;
        $updatedAt = null;

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;

            // TIME\tYYYY-mm-dd HH:MM:SS\tEPOCH
            if (str_starts_with($line, "TIME\t")) {
                $p = explode("\t", $line);
                $epoch = self::clean($p[2] ?? null);
                $updatedAt = ctype_digit((string) $epoch)
                    ? (int) $epoch
                    : self::toEpoch(self::clean($p[1] ?? ''));
                continue;
            }

            // CLIENT_LIST\tCN\tRealAddr\tVirtIP\tVirtIPv6\tBytesRecv\tBytesSent\tSinceStr\tSinceEpoch\tUsername\tClientID...
            if (str_starts_with($line, "CLIENT_LIST")) {
                $p = explode("\t", $line);

                $commonName     = self::clean($p[1]  ?? '');
                $realAddress    = self::clean($p[2]  ?? '');
                $virtualAddress = self::clean($p[3]  ?? '');
                $bytesRecv      = (int) self::clean($p[5]  ?? 0);
                $bytesSent      = (int) self::clean($p[6]  ?? 0);
                $sinceStr       = self::clean($p[7]  ?? null);
                $sinceEpoch     = self::clean($p[8]  ?? null);
                $usernameCol    = self::clean($p[9]  ?? '');
                $clientId       = (int) self::clean($p[10] ?? -1);

                $clientIp    = explode(':', $realAddress)[0] ?? '';
                $username    = $usernameCol !== '' ? $usernameCol : $commonName;
                $connectedAt = self::pickEpoch($sinceEpoch, $sinceStr);

                $clients[] = array_merge(
                    self::hydrateClient(
                        username: $username,
                        clientIp: $clientIp,
                        virtualIp: $virtualAddress,
                        bytesRecv: $bytesRecv,
                        bytesSent: $bytesSent,
                        connectedAt: $connectedAt
                    ),
                    ['client_id' => $clientId]
                );

                $totalRecv += $bytesRecv;
                $totalSent += $bytesSent;
            }
        }

        return [
            'updated_at' => $updatedAt ?? time(),
            'clients'    => $clients,
            'totals'     => ['recv' => $totalRecv, 'sent' => $totalSent],
        ];
    }

    /* ───────────────────────────── helpers ───────────────────────────── */

    protected static function hydrateClient(
        string $username,
        string $clientIp,
        string $virtualIp,
        int $bytesRecv,
        int $bytesSent,
        ?int $connectedAt
    ): array {
        $downMb   = round($bytesRecv / 1048576, 2);
        $upMb     = round($bytesSent / 1048576, 2);
        $totalMb  = round(($bytesRecv + $bytesSent) / 1048576, 2);

        return [
            'username'         => $username,
            'client_ip'        => $clientIp,
            'virtual_ip'       => $virtualIp,
            'bytes_received'   => $bytesRecv,
            'bytes_sent'       => $bytesSent,
            'connected_at'     => $connectedAt,

            // formatted for UI
            'down_mb'          => $downMb,
            'up_mb'            => $upMb,
            'formatted_bytes'  => sprintf('%.2f MB', $totalMb),
            'connected_fmt'    => $connectedAt ? Carbon::createFromTimestamp($connectedAt)->diffForHumans() : null,
            'connected_human'  => $connectedAt ? Carbon::createFromTimestamp($connectedAt)->format('Y-m-d H:i:s') : null,
        ];
    }

    protected static function pickEpoch($epoch, ?string $fallbackStr): ?int
    {
        if (ctype_digit((string) $epoch)) {
            return (int) $epoch;
        }
        return $fallbackStr ? self::toEpoch($fallbackStr) : null;
    }

    protected static function clean($v): string
    {
        $s = (string) ($v ?? '');
        return preg_replace("/\r$/", '', $s) ?? '';
    }

    protected static function toEpoch(string $dt): ?int
    {
        try {
            return Carbon::parse($dt)->timestamp;
        } catch (\Throwable) {
            return null;
        }
    }

    protected static function emptyResult(): array
    {
        return [
            'updated_at' => time(),
            'clients'    => [],
            'totals'     => ['recv' => 0, 'sent' => 0],
        ];
    }
}