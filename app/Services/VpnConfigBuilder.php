<?php

namespace App\Services;

use App\Models\VpnUser;
use App\Models\VpnServer;
use App\Traits\ExecutesRemoteCommands;
use Exception;
use Illuminate\Support\Facades\Log;

class VpnConfigBuilder
{
    use ExecutesRemoteCommands;

    private const UDP_PORT = 1194;
    private const TCP_PORT = 443;

    /**
     * Return the list of expected config files for this user.
     */
    public static function generate(VpnUser $vpnUser): array
    {
        $vpnUser->loadMissing('vpnServers');
        $items = [];

        foreach ($vpnUser->vpnServers as $server) {
            $safeName = preg_replace('/[^\w\-]+/u', '_', $server->name);
            $items[] = [
                'server_id'   => $server->id,
                'server_name' => $server->name,
                'filename'    => "{$safeName}_{$vpnUser->username}_udp.ovpn",
            ];
            $items[] = [
                'server_id'   => $server->id,
                'server_name' => $server->name,
                'filename'    => "{$safeName}_{$vpnUser->username}_tcp443.ovpn",
            ];
        }

        return $items;
    }

    /**
     * Build a stealth (TCP 443) OpenVPN client config.
     */
    public static function generateOpenVpnConfigString(VpnUser $vpnUser, VpnServer $server, string $variant = 'stealth'): string
    {
        $builder = new static();
        [$ca, $ta] = $builder->fetchCertificatesFromServer($server);

        if ($ca === '' || $ta === '') {
            throw new Exception("Missing CA or TLS key for server {$server->name}");
        }

        $endpoint = $server->wg_endpoint_host ?: $server->hostname ?: $server->ip_address;
        if (!$endpoint) {
            throw new Exception("Server {$server->name} has no endpoint host/IP");
        }

        // --- choose variant ---
        $isStealth = $variant === 'stealth' || $variant === 'tcp';
        $proto = $isStealth ? 'tcp-client' : 'udp';
        $port  = $isStealth ? self::TCP_PORT : self::UDP_PORT;

        $username = $vpnUser->username;

        $cfg = <<<OVPN
# === AIOVPN • {$server->name} ({$variant}) ===
# Auto-generated for {$username}

client
dev tun
proto {$proto}
remote {$endpoint} {$port}
resolv-retry infinite
nobind
persist-key
persist-tun
remote-cert-tls server
auth-user-pass
auth-nocache
auth SHA256
cipher AES-128-GCM
data-ciphers AES-128-GCM:CHACHA20-POLY1305:AES-256-GCM
data-ciphers-fallback AES-128-GCM
verb 3
tun-mtu 1500
mssfix 1450

<tls-crypt>
{$ta}
</tls-crypt>

<ca>
{$ca}
</ca>
OVPN;

        Log::info('✅ Built OpenVPN config', [
            'user' => $username,
            'server' => $server->name,
            'variant' => $variant,
            'endpoint' => "{$endpoint}:{$port}",
        ]);

        return $cfg;
    }

    /**
     * Fetch CA and TA from remote server via SSH.
     *
     * @return array [ca, ta]
     */
    private function fetchCertificatesFromServer(VpnServer $server): array
    {
        $ca = '';
        $ta = '';

        try {
            $resCa = $this->executeRemoteCommand($server, 'cat /etc/openvpn/ca.crt');
            if (($resCa['status'] ?? 1) === 0 && !empty($resCa['output'])) {
                $ca = trim(implode("\n", $resCa['output']));
            }

            $resTa = $this->executeRemoteCommand($server, 'cat /etc/openvpn/ta.key');
            if (($resTa['status'] ?? 1) === 0 && !empty($resTa['output'])) {
                $ta = trim(implode("\n", $resTa['output']));
            }

            Log::debug('Fetched CA/TA from server', [
                'server' => $server->name,
                'has_ca' => $ca !== '',
                'has_ta' => $ta !== '',
            ]);
        } catch (Exception $e) {
            Log::error('❌ Failed to fetch CA/TA', [
                'server' => $server->name,
                'error'  => $e->getMessage(),
            ]);
        }

        return [$ca, $ta];
    }

    /**
     * Connectivity check used by admin dashboard.
     */
    public static function testOpenVpnConnectivity(VpnServer $server): array
    {
        $out = [
            'server_reachable' => false,
            'openvpn_udp'      => false,
            'openvpn_tcp'      => false,
            'certs_ok'         => false,
            'details'          => [],
        ];

        $inst = new static();
        try {
            $ssh = $inst->executeRemoteCommand($server, 'echo ok');
            $out['server_reachable'] = ($ssh['status'] ?? 1) === 0;

            if ($out['server_reachable']) {
                $svcUdp = $inst->executeRemoteCommand($server, 'ss -ulnp | grep ":1194"');
                $svcTcp = $inst->executeRemoteCommand($server, 'ss -tlpn | grep ":443"');
                $crt    = $inst->executeRemoteCommand($server, '[ -s /etc/openvpn/ca.crt ] && [ -s /etc/openvpn/ta.key ] && echo ok');

                $out['openvpn_udp'] = !empty($svcUdp['output']);
                $out['openvpn_tcp'] = !empty($svcTcp['output']);
                $out['certs_ok']    = !empty($crt['output']) && in_array('ok', $crt['output'], true);
            }
        } catch (\Throwable $e) {
            $out['details']['error'] = $e->getMessage();
        }

        return $out;
    }
}