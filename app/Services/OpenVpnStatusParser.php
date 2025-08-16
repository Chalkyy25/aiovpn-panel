<?php

namespace App\Services;

use phpseclib3\Net\SSH2;

class OpenVpnStatusParser
{
    public static function fetchRawStatus(SSH2 $ssh, ?string $path = null): string
    {
        $candidates = array_values(array_unique(array_filter([
            $path,
            '/var/log/openvpn-status.log',          // your server
            '/etc/openvpn/openvpn-status.log',
            '/etc/openvpn/server/openvpn-status.log',
            '/run/openvpn/server.status',
            '/var/log/openvpn/openvpn-status.log',
        ])));

        foreach ($candidates as $p) {
            $out = $ssh->exec("test -r '$p' && cat '$p' 2>/dev/null || echo __MISSING__");
            if ($out && trim($out) !== '__MISSING__') return $out;
        }
        throw new \RuntimeException('OpenVPN status file not found in common locations.');
    }

    public static function parse(string $raw): array
    {
        $clients = []; $totRecv = 0; $totSent = 0; $updated = time();
        $clientHeaderMap = null;

        foreach (preg_split("/\r\n|\n|\r/", trim($raw)) as $line) {
            $line = trim($line); if ($line === '' || $line[0] === '#') continue;

            if (str_starts_with($line, 'HEADER,CLIENT_LIST,')) {
                $cols = array_slice(explode(',', $line), 2);
                $clientHeaderMap = [];
                foreach ($cols as $i => $c) $clientHeaderMap[self::norm($c)] = $i;
                continue;
            }

            if (str_starts_with($line, 'TIME,')) {
                $p = explode(',', $line);
                $updated = isset($p[2]) && is_numeric($p[2]) ? (int)$p[2] : (strtotime($p[1] ?? '') ?: $updated);
                continue;
            }
            if (str_starts_with($line, 'Updated,')) {
                $p = explode(',', $line);
                $updated = strtotime($p[1] ?? '') ?: $updated;
                continue;
            }

            if (str_starts_with($line, 'CLIENT_LIST,')) {
                $p = explode(',', $line);

                if ($clientHeaderMap) {
                    $vals = array_slice($p, 1);
                    $get = function (string $name, $def = null) use ($vals, $clientHeaderMap) {
                        $k = self::norm($name);
                        return array_key_exists($k, $clientHeaderMap) ? ($vals[$clientHeaderMap[$k]] ?? $def) : $def;
                    };
                    $name      = (string)$get('Common Name', $get('Username',''));
                    $real      = (string)$get('Real Address', '');
                    $bytesRecv = (int)$get('Bytes Received', 0);
                    $bytesSent = (int)$get('Bytes Sent', 0);
                    $sinceStr  = (string)$get('Connected Since', '');
                } else {
                    if (count($p) < 7) continue;
                    $name      = $p[1] ?? '';
                    $real      = $p[2] ?? '';
                    $bytesRecv = (int)($p[4] ?? 0);
                    $bytesSent = (int)($p[5] ?? 0);
                    $sinceStr  = $p[6] ?? '';
                }

                $sinceTs = strtotime($sinceStr) ?: time();
                $clients[] = [
                    'name'       => $name,
                    'real'       => $real,
                    'bytes_recv' => $bytesRecv, // client→server (server download)
                    'bytes_sent' => $bytesSent, // server→client (server upload)
                    'since'      => $sinceTs,
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

    private static function norm(string $s): string
    {
        return preg_replace('/[\s\-\_\(\)]+/u', '', mb_strtolower($s));
    }
}