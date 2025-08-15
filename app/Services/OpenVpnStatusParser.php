<?php

namespace App\Services;

use phpseclib3\Net\SSH2;

class OpenVpnStatusParser
{
    public static function fetchRawStatus(SSH2 $ssh, string $path = '/etc/openvpn/openvpn-status.log'): string
    {
        $out = $ssh->exec("sudo cat $path 2>/dev/null");
        if (!$out) throw new \RuntimeException("Unable to read $path");
        return $out;
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
            // CLIENT_LIST,Name,Real Address,Virtual Address,Bytes Received,Bytes Sent,Connected Since
            if (str_starts_with($line, 'CLIENT_LIST,')) {
                $parts = explode(',', $line);
                // guard – OpenVPN can append extra fields; we need first 7
                if (count($parts) >= 7) {
                    $name       = $parts[1];
                    $real       = $parts[2];
                    $bytesRecv  = (int)$parts[4];
                    $bytesSent  = (int)$parts[5];
                    $sinceStr   = $parts[6];
                    $sinceTs    = strtotime($sinceStr) ?: time();

                    $clients[] = [
                        'name' => $name,
                        'real' => $real,
                        'bytes_recv' => $bytesRecv,
                        'bytes_sent' => $bytesSent,
                        'since' => $sinceTs,
                    ];
                    $totRecv += $bytesRecv;
                    $totSent += $bytesSent;
                }
            }
            if (str_starts_with($line, 'Updated,') || str_starts_with($line, 'TIME,')) {
                // Updated,2024-01-01 12:34:56 or TIME,1734034302
                $parts = explode(',', $line);
                $updated = is_numeric($parts[1] ?? null) ? (int)$parts[1] : (strtotime($parts[1] ?? '') ?: time());
            }
        }

        return [
            'clients'    => $clients,
            'totals'     => ['recv' => $totRecv, 'sent' => $totSent],
            'updated_at' => $updated,
        ];
    }
}