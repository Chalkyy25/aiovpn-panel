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
            '/run/openvpn/server.status',      // OpenVPN 2.5+ default when started via systemd template
            '/run/openvpn/openvpn.status',     // sometimes used
            '/var/log/openvpn-status.log',     // classic v2 location
        ] as $path) {
            $raw = self::fetchRawStatus($ssh, $path);
            if ($raw !== '') {
                return $raw;
            }
        }
        return '';
    }

    /**
     * Parse either status-version 2 (CSV) or 3 (TSV) automatically.
     *
     * Return shape:
     * [
     *   'updated_at' => int epoch,
     *   'clients' => [
     *      [
     *        'username'       => string,
     *        'client_ip'      => string,
     *        'virtual_ip'     => string,
     *        'bytes_received' => int,  // client -> server
     *        'bytes_sent'     => int,  // server -> client
     *        'connected_at'   => ?int,
     *      ],
     *      ...
     *   ],
     *   'totals' => ['recv' => int, 'sent' => int],
     * ]
     */
    
    public function getConnectedUsernames(string $statusFilePath = '/run/openvpn/server.status'): array
{
    if (!file_exists($statusFilePath)) {
        return [];
    }

    $raw = file_get_contents($statusFilePath);
    $parsed = self::parse($raw);

    return collect($parsed['clients'])->pluck('username')->unique()->values()->all();
    }

    public static function parse(string $raw): array
    {
        $raw = trim($raw ?? '');
        if ($raw === '') {
            return self::emptyResult();
        }

        // Simple heuristics:
        $isV3 = str_contains($raw, "HEADER\tCLIENT_LIST")
             || str_contains($raw, "\tCLIENT_LIST\t")
             || str_contains($raw, "\tTIME\t");

        $isV2 = str_contains($raw, 'HEADER,CLIENT_LIST')
             || str_contains($raw, 'CLIENT_LIST,');

        if ($isV3 && !$isV2) {
            return self::parseV3($raw);
        }
        if ($isV2 && !$isV3) {
            return self::parseV2($raw);
        }

        // Tie-breaker based on delimiter density
        $tabCount   = substr_count($raw, "\t");
        $commaCount = substr_count($raw, ",");
        return $tabCount >= $commaCount ? self::parseV3($raw) : self::parseV2($raw);
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
                $parts = explode(',', $line);
                $epoch = $parts[2] ?? null;
                if (ctype_digit((string)$epoch)) {
                    $updatedAt = (int)$epoch;
                } elseif (!empty($parts[1])) {
                    $updatedAt = self::toEpoch($parts[1]);
                }
                continue;
            }

            // CLIENT_LIST,<Common Name>,<Real Address>,<Virtual Address>,<Virtual IPv6 Address>,<Bytes Received>,<Bytes Sent>,<Connected Since>,<Connected Since (time_t)>,<Username>,<Client ID>,<Peer ID>,<Data Channel Cipher>
            if (str_starts_with($line, 'CLIENT_LIST,')) {
                $p = explode(',', $line);

                $commonName     = $p[1]  ?? '';
                $realAddress    = $p[2]  ?? '';
                $virtualAddress = $p[3]  ?? '';
                $bytesRecv      = (int)($p[5]  ?? 0);
                $bytesSent      = (int)($p[6]  ?? 0);
                $sinceStr       = $p[7]  ?? null;
                $sinceEpoch     = $p[8]  ?? null;
                $usernameCol    = $p[9]  ?? '';

                $clientIp = explode(':', $realAddress)[0] ?? '';
                $username = $usernameCol !== '' ? $usernameCol : $commonName;

                $connectedAt = null;
                if (ctype_digit((string)$sinceEpoch)) {
                    $connectedAt = (int)$sinceEpoch;
                } elseif (!empty($sinceStr)) {
                    $connectedAt = self::toEpoch($sinceStr);
                }

                $clients[] = [
                    'username'       => $username,
                    'client_ip'      => $clientIp,
                    'virtual_ip'     => $virtualAddress,
                    'bytes_received' => $bytesRecv,
                    'bytes_sent'     => $bytesSent,
                    'connected_at'   => $connectedAt,
                ];

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
                $epoch = $p[2] ?? null;
                if (ctype_digit((string)$epoch)) {
                    $updatedAt = (int)$epoch;
                } elseif (!empty($p[1])) {
                    $updatedAt = self::toEpoch($p[1]);
                }
                continue;
            }

            // CLIENT_LIST\tCommonName\tRealAddr\tVirtIP\tVirtIPv6\tBytesRecv\tBytesSent\tConnectedSince\tConnectedEpoch\tUsername...
            if (str_starts_with($line, "CLIENT_LIST\t")) {
                $p = explode("\t", $line);

                $commonName     = $p[1]  ?? '';
                $realAddress    = $p[2]  ?? '';
                $virtualAddress = $p[3]  ?? '';
                $bytesRecv      = (int)($p[5]  ?? 0);
                $bytesSent      = (int)($p[6]  ?? 0);
                $sinceStr       = $p[7]  ?? null;
                $sinceEpoch     = $p[8]  ?? null;
                $usernameCol    = $p[9]  ?? '';

                $clientIp = explode(':', $realAddress)[0] ?? '';
                $username = $usernameCol !== '' ? $usernameCol : $commonName;

                $connectedAt = null;
                if (ctype_digit((string)$sinceEpoch)) {
                    $connectedAt = (int)$sinceEpoch;
                } elseif (!empty($sinceStr)) {
                    $connectedAt = self::toEpoch($sinceStr);
                }

                $clients[] = [
                    'username'       => $username,
                    'client_ip'      => $clientIp,
                    'virtual_ip'     => $virtualAddress,
                    'bytes_received' => $bytesRecv,
                    'bytes_sent'     => $bytesSent,
                    'connected_at'   => $connectedAt,
                ];

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