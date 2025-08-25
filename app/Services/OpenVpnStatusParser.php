<?php

namespace App\Services;

use Carbon\Carbon;
use phpseclib3\Net\SSH2;

class OpenVpnStatusParser
{
    /**
     * Read a specific status file over an existing SSH session.
     */
    public static function fetchRawStatus(SSH2 $ssh, string $path = '/var/log/openvpn-status.log'): string
    {
        $out = $ssh->exec('cat ' . escapeshellarg($path));
        return is_string($out) ? trim($out) : '';
    }

    /**
     * Preferred reader: try common locations (v3 first, then v2).
     * Returns the first non-empty status file contents or empty string.
     */
    public static function fetchAnyStatus(SSH2 $ssh): string
    {
        foreach ([
            '/run/openvpn/server.status',      // OpenVPN 2.5+ (systemd template)
            '/run/openvpn/openvpn.status',     // alt tmpfs path
            '/var/log/openvpn-status.log',     // classic v2 file
        ] as $path) {
            $raw = self::fetchRawStatus($ssh, $path);
            if ($raw !== '') return $raw;
        }
        return '';
    }

    /**
     * Convenience: return unique connected usernames from a local file path.
     */
    public function getConnectedUsernames(string $statusFilePath = '/run/openvpn/server.status'): array
    {
        if (!is_file($statusFilePath)) return [];
        $raw = (string) file_get_contents($statusFilePath);
        $parsed = self::parse($raw);

        return collect($parsed['clients'])->pluck('username')->unique()->values()->all();
    }

    /**
     * Parse either status-version 2 (CSV) or 3 (TSV) automatically.
     *
     * Return shape:
     * [
     *   'updated_at' => int epoch,
     *   'clients' => [
     *      [
     *        'username'         => string,
     *        'client_ip'        => string,
     *        'virtual_ip'       => string,
     *        'bytes_received'   => int,
     *        'bytes_sent'       => int,
     *        'connected_at'     => ?int,
     *        // formatted helpers for UI:
     *        'down_mb'          => float,   // bytes_received in MB
     *        'up_mb'            => float,   // bytes_sent in MB
     *        'formatted_bytes'  => string,  // "X.XX MB"
     *        'connected_fmt'    => ?string, // "5 minutes ago"
     *        'connected_human'  => ?string, // "YYYY-mm-dd HH:MM:SS"
     *      ],
     *      ...
     *   ],
     *   'totals' => ['recv' => int, 'sent' => int],
     * ]
     */
    public static function parse(string $raw): array
    {
        $raw = trim($raw ?? '');
        if ($raw === '') {
            return self::emptyResult();
        }

        // Heuristics for delimiter:
        $isV3 = str_contains($raw, "HEADER\tCLIENT_LIST")
             || str_contains($raw, "\tCLIENT_LIST\t")
             || str_contains($raw, "\tTIME\t");

        $isV2 = str_contains($raw, 'HEADER,CLIENT_LIST')
             || str_contains($raw, 'CLIENT_LIST,');

        if ($isV3 && !$isV2) return self::parseV3($raw);
        if ($isV2 && !$isV3) return self::parseV2($raw);

        // Tie-break by delimiter density
        return substr_count($raw, "\t") >= substr_count($raw, ',')
            ? self::parseV3($raw)
            : self::parseV2($raw);
    }

    /* ───────────────────────────── v2 (CSV) ───────────────────────────── */

    protected static function parseV2(string $raw): array
    {
        $lines = preg_split("/\r\n|\r|\n/", $raw);
        $clients   = [];
        $totalRecv = 0;
        $totalSent = 0;
        $updatedAt = null;

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;

            // TIME,YYYY-mm-dd HH:MM:SS,epoch
            if (str_starts_with($line, 'TIME,')) {
                $p = explode(',', $line);
                $epoch = $p[2] ?? null;
                $updatedAt = ctype_digit((string) $epoch) ? (int) $epoch : self::toEpoch($p[1] ?? '');
                continue;
            }

            // CLIENT_LIST,<CN>,<RealAddr>,<VirtIP>,<VirtIPv6>,<BytesRecv>,<BytesSent>,<SinceStr>,<SinceEpoch>,<Username>,<ClientID>,...
            if (str_starts_with($line, 'CLIENT_LIST')) {
                $p = explode(',', $line);

                $commonName     = $p[1]  ?? '';
                $realAddress    = $p[2]  ?? '';
                $virtualAddress = $p[3]  ?? '';
                $bytesRecv      = (int)($p[5]  ?? 0);
                $bytesSent      = (int)($p[6]  ?? 0);
                $sinceStr       = $p[7]  ?? null;
                $sinceEpoch     = $p[8]  ?? null;
                $usernameCol    = $p[9]  ?? '';

                $clientIp   = explode(':', $realAddress)[0] ?? '';
                $username   = $usernameCol !== '' ? $usernameCol : $commonName;
                $connectedAt = self::pickEpoch($sinceEpoch, $sinceStr);

                $clients[] = self::hydrateClient(
                    username: $username,
                    clientIp: $clientIp,
                    virtualIp: $virtualAddress,
                    bytesRecv: $bytesRecv,
                    bytesSent: $bytesSent,
                    connectedAt: $connectedAt
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
                $updatedAt = ctype_digit((string) $epoch) ? (int) $epoch : self::toEpoch(self::clean($p[1] ?? ''));
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

                $clientIp    = explode(':', $realAddress)[0] ?? '';
                $username    = $usernameCol !== '' ? $usernameCol : $commonName;
                $connectedAt = self::pickEpoch($sinceEpoch, $sinceStr);

                $clients[] = self::hydrateClient(
                    username: $username,
                    clientIp: $clientIp,
                    virtualIp: $virtualAddress,
                    bytesRecv: $bytesRecv,
                    bytesSent: $bytesSent,
                    connectedAt: $connectedAt
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
        $downMb = round($bytesRecv / 1048576, 2);
        $upMb   = round($bytesSent / 1048576, 2);
        $totalMb = round(($bytesRecv + $bytesSent) / 1048576, 2);

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
        // trim trailing \r that mgmt interface sometimes includes
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