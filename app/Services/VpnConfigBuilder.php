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
    private const WG_PORT = 51820;

    /**
     * Return the list of expected config files for this user.
     * Prioritizes stealth modes for ISP bypass.
     */
    public static function generate(VpnUser $vpnUser): array
    {
        $vpnUser->loadMissing('vpnServers');
        $items = [];

        foreach ($vpnUser->vpnServers as $server) {
            $safeName = preg_replace('/[^\w\-]+/u', '_', $server->name);
            
            // Priority 1: TCP 443 Stealth only - RECOMMENDED for ISP bypass
            $items[] = [
                'server_id'   => $server->id,
                'server_name' => $server->name,
                'filename'    => "{$safeName}_{$vpnUser->username}_stealth.ovpn",
                'variant'     => 'stealth',
                'priority'    => 1,
                'description' => 'TCP 443 stealth mode (bypasses most ISP blocks)'
            ];

            // Priority 2: Unified profile (TCP 443 + UDP fallback)
            $items[] = [
                'server_id'   => $server->id,
                'server_name' => $server->name,
                'filename'    => "{$safeName}_{$vpnUser->username}_unified.ovpn",
                'variant'     => 'unified',
                'priority'    => 2,
                'description' => 'Smart profile: TCP 443 stealth with UDP fallback'
            ];

            // Priority 3: UDP fallback
            $items[] = [
                'server_id'   => $server->id,
                'server_name' => $server->name,
                'filename'    => "{$safeName}_{$vpnUser->username}_udp.ovpn",
                'variant'     => 'udp',
                'priority'    => 3,
                'description' => 'Traditional UDP mode (fastest, may be blocked)'
            ];

            // Priority 4: WireGuard (if available)
            if ($server->wg_public_key) {
                $items[] = [
                    'server_id'   => $server->id,
                    'server_name' => $server->name,
                    'filename'    => "{$safeName}_{$vpnUser->username}_wireguard.conf",
                    'variant'     => 'wireguard',
                    'priority'    => 4,
                    'description' => 'WireGuard VPN (modern, fast, requires app support)'
                ];
            }
        }

        // Sort by priority (lowest number = highest priority)
        usort($items, fn($a, $b) => $a['priority'] <=> $b['priority']);

        return $items;
    }

    /**
     * Build OpenVPN client config optimized for ISP bypass.
     */
    public static function generateOpenVpnConfigString(VpnUser $vpnUser, VpnServer $server, string $variant = 'unified'): string
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

        $username = $vpnUser->username;
        $password = $vpnUser->plain_password; // For embedded auth

        return match ($variant) {
            'unified' => $builder->buildUnifiedConfig($username, $password, $server->name, $endpoint, $ca, $ta),
            'stealth' => $builder->buildStealthConfig($username, $password, $server->name, $endpoint, $ca, $ta),
            'udp'     => $builder->buildUdpConfig($username, $password, $server->name, $endpoint, $ca, $ta),
            default   => throw new Exception("Unknown variant: {$variant}")
        };
    }

    /**
     * Build unified profile (TCP 443 primary + UDP fallback) - RECOMMENDED
     */
    private function buildUnifiedConfig(string $username, string $password, string $serverName, string $endpoint, string $ca, string $ta): string
    {
        $cfg = <<<OVPN
# === AIOVPN • {$serverName} (Unified Stealth) ===
# Auto-generated for {$username}
# Tries TCP 443 first (stealth), falls back to UDP

client
dev tun
resolv-retry infinite
nobind
persist-key
persist-tun
remote-cert-tls server
auth SHA256
auth-user-pass
auth-nocache
verb 3

# Connection attempts with fast failover for ISP testing
remote-random
connect-retry 1
connect-retry-max 1
connect-timeout 4

# Primary: TCP 443 (stealth mode - bypasses ISP blocks)
remote {$endpoint} 443 tcp

# Fallback: UDP 1194 (traditional mode - faster)  
remote {$endpoint} 1194 udp

# Modern cipher negotiation (OpenVPN 2.6+ optimized)
data-ciphers AES-128-GCM:CHACHA20-POLY1305:AES-256-GCM
data-ciphers-fallback AES-128-GCM
cipher AES-128-GCM
pull-filter ignore "cipher"
pull-filter ignore "auth"

# Performance optimizations
mute-replay-warnings
tun-mtu 1500
mssfix 1450

<auth-user-pass>
{$username}
{$password}
</auth-user-pass>

<tls-crypt>
{$ta}
</tls-crypt>

<ca>
{$ca}
</ca>
OVPN;

        // Log::info('✅ Built unified OpenVPN config (stealth+fallback)', [
        //     'user' => $username,
        //     'server' => $serverName,
        //     'endpoint' => $endpoint,
        //     'mode' => 'TCP443+UDP1194'
        // ]);

        return $cfg;
    }

    /**
     * Build TCP 443 stealth-only config
     */
    private function buildStealthConfig(string $username, string $password, string $serverName, string $endpoint, string $ca, string $ta): string
    {
        $cfg = <<<OVPN
# === AIOVPN • {$serverName} (TCP 443 Stealth) ===
# Auto-generated for {$username}
# Pure stealth mode - appears as HTTPS traffic

client
dev tun
proto tcp
remote {$endpoint} 443
resolv-retry infinite
nobind
persist-key
persist-tun
remote-cert-tls server
auth SHA256
auth-user-pass
auth-nocache
verb 3

# Fast connection for ISP block testing
connect-retry 1
connect-retry-max 1
connect-timeout 4

# Modern cipher negotiation and performance
data-ciphers AES-128-GCM:CHACHA20-POLY1305:AES-256-GCM
data-ciphers-fallback AES-128-GCM
cipher AES-128-GCM
pull-filter ignore "cipher"
pull-filter ignore "auth"
mute-replay-warnings

# TCP-optimized MTU
tun-mtu 1500
mssfix 1450

<auth-user-pass>
{$username}
{$password}
</auth-user-pass>

<tls-crypt>
{$ta}
</tls-crypt>

<ca>
{$ca}
</ca>
OVPN;

        // Log::info('✅ Built stealth OpenVPN config (TCP 443)', [
        //     'user' => $username,
        //     'server' => $serverName,
        //     'endpoint' => "{$endpoint}:443",
        //     'mode' => 'TCP443_STEALTH'
        // ]);

        return $cfg;
    }

    /**
     * Build traditional UDP config (fallback only)
     */
    private function buildUdpConfig(string $username, string $password, string $serverName, string $endpoint, string $ca, string $ta): string
    {
        $cfg = <<<OVPN
# === AIOVPN • {$serverName} (UDP Traditional) ===
# Auto-generated for {$username}
# Traditional UDP mode - fastest but may be blocked

client
dev tun
proto udp
remote {$endpoint} 1194
resolv-retry infinite
nobind
persist-key
persist-tun
remote-cert-tls server
auth SHA256
auth-user-pass
auth-nocache
verb 3

# Modern cipher negotiation (OpenVPN 2.6+)
data-ciphers AES-128-GCM:CHACHA20-POLY1305:AES-256-GCM
data-ciphers-fallback AES-128-GCM
cipher AES-128-GCM
pull-filter ignore "cipher"
pull-filter ignore "auth"

# UDP optimizations
explicit-exit-notify 3
tun-mtu 1500
mssfix 1450

<auth-user-pass>
{$username}
{$password}
</auth-user-pass>

<tls-crypt>
{$ta}
</tls-crypt>

<ca>
{$ca}
</ca>
OVPN;

        Log::info('✅ Built UDP OpenVPN config', [
            'user' => $username,
            'server' => $serverName,
            'endpoint' => "{$endpoint}:1194",
            'mode' => 'UDP1194_TRADITIONAL'
        ]);

        return $cfg;
    }

    /**
     * Generate WireGuard config (if server supports it)
     */
    public static function generateWireGuardConfigString(VpnUser $vpnUser, VpnServer $server): string
    {
        if (!$server->wg_public_key) {
            throw new Exception("Server {$server->name} does not support WireGuard");
        }

        $endpoint = $server->wg_endpoint_host ?: $server->hostname ?: $server->ip_address;
        $wgPort = $server->wg_port ?: self::WG_PORT;

        // Generate client keys (this should ideally be stored per user)
        $privateKey = sodium_bin2base64(random_bytes(32), SODIUM_BASE64_VARIANT_ORIGINAL);
        $publicKey = sodium_bin2base64(random_bytes(32), SODIUM_BASE64_VARIANT_ORIGINAL);

        $cfg = <<<WG
[Interface]
PrivateKey = {$privateKey}
Address = 10.66.66.100/32
DNS = 10.66.66.1

[Peer]
PublicKey = {$server->wg_public_key}
Endpoint = {$endpoint}:{$wgPort}
AllowedIPs = 0.0.0.0/0
PersistentKeepalive = 25
WG;

        Log::info('✅ Built WireGuard config', [
            'user' => $vpnUser->username,
            'server' => $server->name,
            'endpoint' => "{$endpoint}:{$wgPort}",
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
     * Enhanced connectivity check for stealth deployment.
     */
    public static function testOpenVpnConnectivity(VpnServer $server): array
    {
        $out = [
            'server_reachable'    => false,
            'openvpn_udp'        => false,
            'openvpn_tcp_stealth' => false,
            'wireguard'          => false,
            'private_dns'        => false,
            'certs_ok'           => false,
            'stealth_recommended' => true,
            'details'            => [],
        ];

        $inst = new static();
        try {
            $ssh = $inst->executeRemoteCommand($server, 'echo ok');
            $out['server_reachable'] = ($ssh['status'] ?? 1) === 0;

            if ($out['server_reachable']) {
                // Check OpenVPN UDP (traditional)
                $svcUdp = $inst->executeRemoteCommand($server, 'ss -ulnp | grep ":1194"');
                $out['openvpn_udp'] = !empty($svcUdp['output']);

                // Check OpenVPN TCP 443 (stealth)
                $svcTcp = $inst->executeRemoteCommand($server, 'ss -tlnp | grep ":443"');
                $out['openvpn_tcp_stealth'] = !empty($svcTcp['output']);

                // Check WireGuard
                $wgCheck = $inst->executeRemoteCommand($server, 'ss -ulnp | grep ":51820" && ip addr show wg0');
                $out['wireguard'] = !empty($wgCheck['output']);

                // Check private DNS
                $dnsCheck = $inst->executeRemoteCommand($server, 'ss -ulnp | grep "10.66.66.1:53"');
                $out['private_dns'] = !empty($dnsCheck['output']);

                // Check certificates
                $crt = $inst->executeRemoteCommand($server, '[ -s /etc/openvpn/ca.crt ] && [ -s /etc/openvpn/ta.key ] && echo ok');
                $out['certs_ok'] = !empty($crt['output']) && in_array('ok', $crt['output'], true);

                // Check deployment status
                $deployStatus = $inst->executeRemoteCommand($server, 'ls -la /root/clients/aio-*.ovpn 2>/dev/null | wc -l');
                $configCount = intval(trim(implode('', $deployStatus['output'] ?? [])));
                $out['details']['config_files'] = $configCount;
                $out['details']['stealth_deployed'] = $out['openvpn_tcp_stealth'];
                $out['details']['unified_profile'] = $configCount >= 3; // Should have UDP, TCP, and unified configs
            }
        } catch (\Throwable $e) {
            $out['details']['error'] = $e->getMessage();
        }

        // Recommend stealth if UDP is blocked but TCP works
        $out['stealth_recommended'] = !$out['openvpn_udp'] || $out['openvpn_tcp_stealth'];

        return $out;
    }

    /**
     * Get recommended config variant for user based on server capabilities
     */
    public static function getRecommendedVariant(VpnServer $server): string
    {
        $connectivity = static::testOpenVpnConnectivity($server);
        
        // Prioritize based on what's available and ISP bypass capability
        if ($connectivity['openvpn_tcp_stealth'] && $connectivity['openvpn_udp']) {
            return 'unified'; // Best option: stealth + fallback
        }
        
        if ($connectivity['openvpn_tcp_stealth']) {
            return 'stealth'; // Stealth only
        }
        
        if ($connectivity['wireguard']) {
            return 'wireguard'; // Modern alternative
        }
        
        return 'udp'; // Traditional fallback
    }
}