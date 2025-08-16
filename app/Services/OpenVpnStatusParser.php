<?php

namespace App\Services;

use phpseclib3\Net\SSH2;

class OpenVpnStatusParser
{
    public static function fetchRawStatus(SSH2 $ssh, string $path = null): string
{
    $candidates = array_filter([
        $path,
        '/var/log/openvpn-status.log',          // <-- your server uses this
        '/etc/openvpn/openvpn-status.log',
        '/etc/openvpn/server/openvpn-status.log',
        '/run/openvpn/server.status',
    ]);

    foreach ($candidates as $p) {
        $out = $ssh->exec("test -r $p && cat $p 2>/dev/null || echo __MISSING__");
        if ($out && trim($out) !== '__MISSING__') return $out;
    }
    throw new \RuntimeException('OpenVPN status file not found.');
}

    /**
     * Returns:
     * [
     *   'clients' => [
     *      [
     *        'name' => 'ashley',
     *        'real' => '1.2.3.4:51820',
     *        'bytes_recv' => 123456,   // client -> server (download to server)
     *        'bytes_sent' => 987654,   // server -> client (upload from server)
     *        'since' => 1723740000
     *      ], ...
     *   ],
     *   'totals' => ['recv' => int, 'sent' => int],
     *   'updated_at' => 1723740337
     * ]
     */
    public static function parse(string $raw): array
{
    $clients = [];
    $totRecv = 0; $totSent = 0; $updated = time();

    foreach (explode("\n", trim($raw)) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;

        if (str_starts_with($line, 'CLIENT_LIST,')) {
            $p = explode(',', $line);

            // status-version 2 layout (has IPv6 + more fields)
            // CLIENT_LIST,Name,Real,Virtual,Virtual6,BytesRecv,BytesSent,Since,Since_t,Username,ClientID,PeerID,Cipher
            if (count($p) >= 12) {
                $name      = $p[1];
                $real      = $p[2];
                $bytesRecv = (int)$p[5];
                $bytesSent = (int)$p[6];
                $sinceStr  = $p[7];
            }
            // older layout (no IPv6 column)
            // CLIENT_LIST,Name,Real,Virtual,BytesRecv,BytesSent,Since
            elseif (count($p) >= 7) {
                $name      = $p[1];
                $real      = $p[2];
                $bytesRecv = (int)$p[4];
                $bytesSent = (int)$p[5];
                $sinceStr  = $p[6];
            } else {
                continue; // unexpected layout
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

        if (str_starts_with($line, 'TIME,')) {
            $parts = explode(',', $line);
            // e.g. TIME,2025-08-16 03:27:18,1755314838
            $updated = isset($parts[2]) && is_numeric($parts[2])
                ? (int)$parts[2]
                : (strtotime($parts[1] ?? '') ?: time());
        }
        if (str_starts_with($line, 'Updated,')) {
            $parts = explode(',', $line);
            $updated = strtotime($parts[1] ?? '') ?: $updated;
        }
    }

    return [
        'clients'    => $clients,
        'totals'     => ['recv' => $totRecv, 'sent' => $totSent],
        'updated_at' => $updated,
    ];
}
}