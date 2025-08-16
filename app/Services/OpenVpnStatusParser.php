<?php

namespace App\Services;

use phpseclib3\Net\SSH2;

class OpenVpnStatusParser
{
    /**
     * Try to read the OpenVPN status file.
     * If $path is null, tries common locations (prefers your server's path).
     */
    public static function fetchRawStatus(SSH2 $ssh, ?string $path = null): string
    {
        $candidates = array_values(array_unique(array_filter([
            $path,
            // most common spots (put your known-good path first)
            '/var/log/openvpn-status.log',          // <-- your server uses this
            '/etc/openvpn/openvpn-status.log',
            '/etc/openvpn/server/openvpn-status.log',
            '/run/openvpn/server.status',
            '/var/log/openvpn/openvpn-status.log',
        ])));

        foreach ($candidates as $p) {
            // only cat if readable to avoid sudo needs
            $out = $ssh->exec("test -r '$p' && cat '$p' 2>/dev/null || echo __MISSING__");
            if ($out && trim($out) !== '__MISSING__') {
                return $out;
            }
        }

        throw new \RuntimeException('OpenVPN status file not found in common locations.');
    }

    /**
     * Parse the status contents.
     *
     * Returns:
     * [
     *   'clients' => [
     *      [
     *        'name' => 'user',
     *        'real' => '1.2.3.4:56789',
     *        'bytes_recv' => 123,   // client->server (download to server)
     *        'bytes_sent' => 456,   // server->client (upload from server)
     *        'since' => 1723740000,
     *      ], ...
     *   ],
     *   'totals' => ['recv' => int, 'sent' => int],
     *   'updated_at' => 1723740337
     * ]
     */
    public static function parse(string $raw): array
    {
        $clients = [];
        $totRecv = 0;
        $totSent = 0;
        $updated = time();

        // For status-version 2, OpenVPN emits a header describing CLIENT_LIST columns:
        // HEADER,CLIENT_LIST,Common Name,Real Address,Virtual Address,Virtual IPv6 Address,Bytes Received,Bytes Sent,Connected Since,Connected Since (time_t),Username,Client ID,Peer ID,Data Channel Cipher
        $clientHeaderMap = null; // normalizedName => index (relative to first CLIENT_LIST value)

        $lines = preg_split("/\r\n|\n|\r/", trim($raw));
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') continue;

            // Capture the header row (status-version 2)
            if (str_starts_with($line, 'HEADER,CLIENT_LIST,')) {
                $parts = explode(',', $line);
                // columns start after "HEADER,CLIENT_LIST"
                $cols = array_slice($parts, 2);
                $clientHeaderMap = [];
                foreach ($cols as $i => $colName) {
                    $clientHeaderMap[self::norm($colName)] = $i; // index within CLIENT_LIST values
                }
                continue;
            }

            // Parse TIME (either TIME,<string>,<epoch> or TIME,<string>)
            if (str_starts_with($line, 'TIME,')) {
                $parts = explode(',', $line);
                if (isset($parts[2]) && is_numeric($parts[2])) {
                    $updated = (int) $parts[2];
                } else {
                    $updated = strtotime($parts[1] ?? '') ?: $updated;
                }
                continue;
            }

            // Some builds emit "Updated,<datetime>"
            if (str_starts_with($line, 'Updated,')) {
                $parts = explode(',', $line);
                $updated = strtotime($parts[1] ?? '') ?: $updated;
                continue;
            }

            // Parse client rows
            if (str_starts_with($line, 'CLIENT_LIST,')) {
                $p = explode(',', $line);

                // Values for CLIENT_LIST start at index 1
                // If we have a header map, use it → safest across versions
                if ($clientHeaderMap && count($p) >= 2) {
                    $vals = array_slice($p, 1);

                    $get = function (string $name, $default = null) use ($vals, $clientHeaderMap) {
                        $key = self::norm($name);
                        if (!array_key_exists($key, $clientHeaderMap)) return $default;
                        $idx = $clientHeaderMap[$key];
                        return $vals[$idx] ?? $default;
                    };

                    $name      = (string) $get('Common Name', $get('Username', ''));
                    $real      = (string) $get('Real Address', '');
                    $bytesRecv = (int) ($get('Bytes Received', 0));
                    $bytesSent = (int) ($get('Bytes Sent', 0));
                    $sinceStr  = (string) $get('Connected Since', '');
                }
                // Fallback to older layout: CLIENT_LIST,Name,Real,Virtual,BytesRecv,BytesSent,Since
                else {
                    if (count($p) < 7) continue;
                    $name      = $p[1] ?? '';
                    $real      = $p[2] ?? '';
                    $bytesRecv = (int) ($p[4] ?? 0);
                    $bytesSent = (int) ($p[5] ?? 0);
                    $sinceStr  = $p[6] ?? '';
                }

                $sinceTs = strtotime($sinceStr) ?: time();

                $clients[] = [
                    'name'        => $name,
                    'real'        => $real,
                    'bytes_recv'  => $bytesRecv,
                    'bytes_sent'  => $bytesSent,
                    'since'       => $sinceTs,
                ];

                $totRecv += $bytesRecv;
                $totSent += $bytesSent;
            }
        }

        return [
            'clients'    => $clients,
            'totals'     => ['recv' => $totRecv, 'sent' => $totSent],
            'updated_at' => $updated,
        ];
    }

    /** Normalize header names so we can match "Bytes Received" vs "Bytes  Received" etc. */
    private static function norm(string $s): string
    {
        $s = mb_strtolower($s);
        // drop punctuation and spaces/parentheses/dashes/underscores
        $s = preg_replace('/[\s\-\_\(\)]+/u', '', $s);
        return $s;
    }
}