<?php

namespace App\Services;

use Carbon\Carbon;
use phpseclib3\Net\SSH2;

class OpenVpnStatusParser
{
    /**
     * Fetch raw status file contents over an existing SSH session.
     */
    public static function fetchRawStatus(SSH2 $ssh, string $path = '/var/log/openvpn-status.log'): string
    {
        $out = $ssh->exec("cat " . escapeshellarg($path));
        return is_string($out) ? $out : '';
    }

    /**
     * Parse either status-version 2 (CSV) or 3 (TSV) automatically.
     * Returns:
     *  [
     *    'updated_at' => int epoch,
     *    'clients' => [
     *       ['username'=>..., 'client_ip'=>..., 'virtual_ip'=>..., 'bytes_received'=>int, 'bytes_sent'=>int, 'connected_at'=>int|null],
     *    ],
     *    'totals' => ['recv'=>int, 'sent'=>int],
     *  ]
     */
    public static function parse(string $raw): array
    {
        $raw = trim($raw ?? '');
        if ($raw === '') {
            return self::emptyResult();
        }

        // Heuristics to detect v3 vs v2
        // v3 has TAB-separated lines and looks like: "HEADER\tCLIENT_LIST\tCommon Name\t..."
        $isV3 = str_contains($raw, "HEADER\tCLIENT_LIST") || (
            // Some v3 files don’t include HEADER line in the head snippet; fallback:
            str_contains($raw, "\tCLIENT_LIST\t") || (str_contains($raw, "\tTIME\t"))
        );

        // v2 is comma-separated "HEADER,CLIENT_LIST,..."
        $isV2 = str_contains($raw, 'HEADER,CLIENT_LIST') || str_contains($raw, 'CLIENT_LIST,');

        if ($isV3 && !$isV2) {
            return self::parseV3($raw);
        }

        if ($isV2 && !$isV3) {
            return self::parseV2($raw);
        }

        // Tie-breaker: prefer v3 if tabs dominate, otherwise v2.
        $tabCount   = substr_count($raw, "\t");
        $commaCount = substr_count($raw, ",");

        return $tabCount >= $commaCount ? self::parseV3($raw) : self::parseV2($raw);
    }

    /* ----------------------------- v2 (CSV) ----------------------------- */

    protected static function parseV2(string $raw): array
    {
        $lines = preg_split("/\r\n|\r|\n/", $raw);
        $clients = [];
        $totalRecv = 0;
        $totalSent = 0;
        $updatedAt = null; // epoch if possible

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;

            // TIME,YYYY-mm-dd HH:MM:SS,epoch
            if (str_starts_with($line, 'TIME,')) {
                $parts = explode(',', $line);
                // usually: [TIME, Y-m-d H:i:s, epoch]
                $epoch = $parts[2] ?? null;
                if (ctype_digit((string)$epoch)) {
                    $updatedAt = (int)$epoch;
                } else {
                    $dt = $parts[1] ?? null;
                    $updatedAt = $dt ? self::toEpoch($dt) : null;
                }
                continue;
            }

            // CLIENT_LIST,<Common Name>,<Real Address>,<Virtual Address>,<Virtual IPv6 Address>,<Bytes Received>,<Bytes Sent>,<Connected Since>,<Connected Since (time_t)>,<Username>,<Client ID>,<Peer ID>,<Data Channel Cipher>
            if (str_starts_with($line, 'CLIENT_LIST,')) {
                $parts = explode(',', $line);

                // Defensive checks for indices (OpenVPN sometimes adds columns)
                $commonName      = $parts[1]  ?? '';
                $realAddress     = $parts[2]  ?? '';
                $virtualAddress  = $parts[3]  ?? '';
                $bytesReceived   = (int) ($parts[5]  ?? 0);
                $bytesSent       = (int) ($parts[6]  ?? 0);
                $connectedSince  = $parts[7]  ?? null; // string
                $connectedEpoch  = $parts[8]  ?? null; // epoch if provided
                $usernameCol     = $parts[9]  ?? '';   // present when username-as-common-name

                $clientIp = explode(':', $realAddress)[0] ?? '';
                $username = $usernameCol !== '' ? $usernameCol : $commonName;

                $connectedAt = null;
                if (ctype_digit((string)$connectedEpoch)) {
                    $connectedAt = (int)$connectedEpoch;
                } elseif (!empty($connectedSince)) {
                    $connectedAt = self::toEpoch($connectedSince);
                }

                $clients[] = [
                    'username'       => $username,
                    'client_ip'      => $clientIp,
                    'virtual_ip'     => $virtualAddress,
                    'bytes_received' => $bytesReceived,
                    'bytes_sent'     => $bytesSent,
                    'connected_at'   => $connectedAt,
                ];

                $totalRecv += $bytesReceived;
                $totalSent += $bytesSent;
            }
        }

        return [
            'updated_at' => $updatedAt ?? time(),
            'clients'    => $clients,
            'totals'     => ['recv' => $totalRecv, 'sent' => $totalSent],
        ];
    }

    /* ----------------------------- v3 (TSV) ----------------------------- */

    protected static function parseV3(string $raw): array
    {
        $lines = preg_split("/\r\n|\r|\n/", $raw);
        $clients = [];
        $totalRecv = 0;
        $totalSent = 0;
        $updatedAt = null;

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;

            // TIME \t 2025-08-16 23:38:16 \t 1755387496
            if (str_starts_with($line, "TIME\t")) {
                $parts = explode("\t", $line);
                // parts[1] datetime, parts[2] epoch (sometimes present)
                $epoch = $parts[2] ?? null;
                if (ctype_digit((string)$epoch)) {
                    $updatedAt = (int)$epoch;
                } else {
                    $dt = $parts[1] ?? null;
                    $updatedAt = $dt ? self::toEpoch($dt) : null;
                }
                continue;
            }

            // CLIENT_LIST \t CommonName \t RealAddr \t VirtIP \t VirtIPv6 \t BytesRecv \t BytesSent \t ConnectedSince \t ConnectedEpoch \t Username ...
            if (str_starts_with($line, "CLIENT_LIST\t")) {
                $parts = explode("\t", $line);

                $commonName      = $parts[1]  ?? '';
                $realAddress     = $parts[2]  ?? '';
                $virtualAddress  = $parts[3]  ?? '';
                $bytesReceived   = (int) ($parts[5]  ?? 0);
                $bytesSent       = (int) ($parts[6]  ?? 0);
                $connectedSince  = $parts[7]  ?? null;
                $connectedEpoch  = $parts[8]  ?? null;
                $usernameCol     = $parts[9]  ?? '';

                $clientIp = explode(':', $realAddress)[0] ?? '';
                $username = $usernameCol !== '' ? $usernameCol : $commonName;

                $connectedAt = null;
                if (ctype_digit((string)$connectedEpoch)) {
                    $connectedAt = (int)$connectedEpoch;
                } elseif (!empty($connectedSince)) {
                    $connectedAt = self::toEpoch($connectedSince);
                }

                $clients[] = [
                    'username'       => $username,
                    'client_ip'      => $clientIp,
                    'virtual_ip'     => $virtualAddress,
                    'bytes_received' => $bytesReceived,
                    'bytes_sent'     => $bytesSent,
                    'connected_at'   => $connectedAt,
                ];

                $totalRecv += $bytesReceived;
                $totalSent += $bytesSent;
            }
        }

        return [
            'updated_at' => $updatedAt ?? time(),
            'clients'    => $clients,
            'totals'     => ['recv' => $totalRecv, 'sent' => $totalSent],
        ];
    }

    /* ----------------------------- helpers ----------------------------- */

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