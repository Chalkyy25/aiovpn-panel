<?php

namespace App\Services;

use App\Models\VpnUser;
use App\Models\VpnServer;
use App\Traits\ExecutesRemoteCommands;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class VpnConfigBuilder
{
    use ExecutesRemoteCommands;
    /**
     * Generate OpenVPN configs for all servers assigned to the user.
     */
    public static function generate(VpnUser $vpnUser): array
    {
        $generatedFiles = [];

        foreach ($vpnUser->vpnServers as $server) {
            $caCert = trim(Storage::disk('local')->get("certs/{$server->id}/ca.crt"));
            $tlsKey = trim(Storage::disk('local')->get("certs/{$server->id}/ta.key"));

            $config = <<<EOL
client
dev tun
proto udp
remote {$server->ip_address} 1194
resolv-retry infinite
nobind
persist-key
persist-tun
remote-cert-tls server
auth-user-pass
auth SHA256
cipher AES-256-CBC
verb 3
<ca>
$caCert
</ca>
<tls-auth>
$tlsKey
</tls-auth>
key-direction 1

# Embedded user-pass
<auth-user-pass>
{$vpnUser->username}
{$vpnUser->password}
</auth-user-pass>
EOL;

            // ✅ Create filename based on server name + username
            $safeServerName = str_replace([' ', '(', ')'], ['_', '', ''], $server->name);
            $fileName = "{$safeServerName}_{$vpnUser->username}.ovpn";

            Storage::disk('local')->put("configs/{$fileName}", $config);
            $generatedFiles[] = storage_path("app/configs/{$fileName}");

            Log::info("✅ OpenVPN config generated: {$fileName}");
        }

        return $generatedFiles;
    }

    /**
     * Generate WireGuard config for the user.
     */
    public static function generateWireGuard(VpnUser $vpnUser): string
    {
        // Assuming only one server for WireGuard per user
        $server = $vpnUser->vpnServers->first();

        if (!$server) {
            Log::warning("⚠️ No server assigned to user {$vpnUser->username} for WireGuard config.");
            return '';
        }

        $serverPublicKey = trim(Storage::disk('local')->get("wireguard/{$server->id}/server_public_key"));
        $serverEndpoint = "{$server->ip_address}:51820";

        $config = <<<EOL
[Interface]
PrivateKey = {$vpnUser->wireguard_private_key}
Address = {$vpnUser->wireguard_address}
DNS = 1.1.1.1

[Peer]
PublicKey = {$serverPublicKey}
Endpoint = {$serverEndpoint}
AllowedIPs = 0.0.0.0/0, ::/0
PersistentKeepalive = 25
EOL;

        $fileName = "{$vpnUser->username}.conf";
        Storage::disk('local')->put("configs/{$fileName}", $config);

        Log::info("✅ WireGuard config generated: {$fileName}");

        return storage_path("app/configs/{$fileName}");
    }

    /**
     * Generate OpenVPN config for a specific server without saving to file.
     * Returns the config content as a string.
     */
    public static function generateOpenVpnConfigString(VpnUser $vpnUser, VpnServer $server): string
    {
        try {
            // Try to get certificates from storage first
            $caCert = '';
            $tlsKey = '';

            if (Storage::disk('local')->exists("certs/{$server->id}/ca.crt")) {
                $caCert = trim(Storage::disk('local')->get("certs/{$server->id}/ca.crt"));
            }

            if (Storage::disk('local')->exists("certs/{$server->id}/ta.key")) {
                $tlsKey = trim(Storage::disk('local')->get("certs/{$server->id}/ta.key"));
            }

            // If certificates not found in storage, try to fetch from server
            if (empty($caCert) || empty($tlsKey)) {
                $instance = new static();
                $fetchedCerts = $instance->fetchCertificatesFromServer($server);
                if (!empty($fetchedCerts['ca'])) $caCert = $fetchedCerts['ca'];
                if (!empty($fetchedCerts['ta'])) $tlsKey = $fetchedCerts['ta'];
            }

            $config = <<<EOL
client
dev tun
proto udp
remote {$server->ip_address} 1194
resolv-retry infinite
nobind
persist-key
persist-tun
remote-cert-tls server
auth-user-pass
auth SHA256
cipher AES-256-CBC
verb 3
<ca>
$caCert
</ca>
<tls-auth>
$tlsKey
</tls-auth>
key-direction 1

# Embedded user-pass
<auth-user-pass>
{$vpnUser->username}
{$vpnUser->password}
</auth-user-pass>
EOL;

            Log::info("✅ OpenVPN config generated for {$vpnUser->username} on server {$server->name}");
            return $config;

        } catch (\Exception $e) {
            Log::error("❌ Failed to generate OpenVPN config for {$vpnUser->username} on server {$server->name}: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Fetch certificates from the OpenVPN server.
     */
    private function fetchCertificatesFromServer(VpnServer $server): array
    {
        $certs = ['ca' => '', 'ta' => ''];

        try {
            // Fetch CA certificate
            $caResult = $this->executeRemoteCommand($server->ip_address, 'cat /etc/openvpn/ca.crt');
            if ($caResult['status'] === 0 && !empty($caResult['output'])) {
                $certs['ca'] = implode("\n", $caResult['output']);
            }

            // Fetch TLS auth key
            $taResult = $this->executeRemoteCommand($server->ip_address, 'cat /etc/openvpn/ta.key');
            if ($taResult['status'] === 0 && !empty($taResult['output'])) {
                $certs['ta'] = implode("\n", $taResult['output']);
            }

            Log::info("✅ Certificates fetched from server {$server->name}");

        } catch (\Exception $e) {
            Log::error("❌ Failed to fetch certificates from server {$server->name}: " . $e->getMessage());
        }

        return $certs;
    }

    /**
     * Get real-time OpenVPN sessions from server.
     */
    public static function getLiveOpenVpnSessions(VpnServer $server): array
    {
        $instance = new static();
        $sessions = [];

        try {
            // Get OpenVPN status log
            $result = $instance->executeRemoteCommand($server->ip_address, 'cat /etc/openvpn/openvpn-status.log');

            if ($result['status'] !== 0) {
                Log::warning("⚠️ Could not fetch OpenVPN status from server {$server->name}");
                return $sessions;
            }

            $statusLog = implode("\n", $result['output']);
            $sessions = $instance->parseOpenVpnStatusLog($statusLog);

            Log::info("✅ Fetched " . count($sessions) . " active sessions from server {$server->name}");

        } catch (\Exception $e) {
            Log::error("❌ Failed to get live sessions from server {$server->name}: " . $e->getMessage());
        }

        return $sessions;
    }

    /**
     * Parse OpenVPN status log to extract active sessions.
     */
    private function parseOpenVpnStatusLog(string $statusLog): array
    {
        $sessions = [];
        $lines = explode("\n", $statusLog);
        $inClientSection = false;

        foreach ($lines as $line) {
            $line = trim($line);

            // Start of client list section
            if (strpos($line, 'Common Name,Real Address,Bytes Received,Bytes Sent,Connected Since') !== false) {
                $inClientSection = true;
                continue;
            }

            // End of client list section
            if (strpos($line, 'ROUTING TABLE') !== false) {
                $inClientSection = false;
                break;
            }

            // Parse client data
            if ($inClientSection && !empty($line) && strpos($line, ',') !== false) {
                $parts = explode(',', $line);
                if (count($parts) >= 5) {
                    $sessions[] = [
                        'username' => $parts[0],
                        'real_address' => $parts[1],
                        'bytes_received' => (int)$parts[2],
                        'bytes_sent' => (int)$parts[3],
                        'connected_since' => $parts[4],
                        'total_bytes' => (int)$parts[2] + (int)$parts[3],
                        'formatted_bytes' => $this->formatBytes((int)$parts[2] + (int)$parts[3])
                    ];
                }
            }
        }

        return $sessions;
    }

    /**
     * Format bytes to human readable format.
     */
    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . ' GB';
        } elseif ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        }

        return $bytes . ' B';
    }

    /**
     * Test OpenVPN connectivity to a server.
     */
    public static function testOpenVpnConnectivity(VpnServer $server): array
    {
        $instance = new static();
        $results = [
            'server_reachable' => false,
            'openvpn_running' => false,
            'port_open' => false,
            'certificates_available' => false,
            'details' => []
        ];

        try {
            // Test SSH connectivity
            $sshResult = $instance->executeRemoteCommand($server->ip_address, 'echo "SSH connection successful"');
            $results['server_reachable'] = ($sshResult['status'] === 0);
            $results['details']['ssh'] = $sshResult;

            if ($results['server_reachable']) {
                // Test OpenVPN service status
                $serviceResult = $instance->executeRemoteCommand($server->ip_address, 'systemctl is-active openvpn@server');
                $results['openvpn_running'] = ($serviceResult['status'] === 0 && in_array('active', $serviceResult['output']));
                $results['details']['service'] = $serviceResult;

                // Test OpenVPN port
                $portResult = $instance->executeRemoteCommand($server->ip_address, 'netstat -ulnp | grep :1194');
                $results['port_open'] = ($portResult['status'] === 0 && !empty($portResult['output']));
                $results['details']['port'] = $portResult;

                // Test certificate availability
                $certResult = $instance->executeRemoteCommand($server->ip_address, 'ls -la /etc/openvpn/ca.crt /etc/openvpn/ta.key');
                $results['certificates_available'] = ($certResult['status'] === 0);
                $results['details']['certificates'] = $certResult;
            }

            Log::info("✅ OpenVPN connectivity test completed for server {$server->name}");

        } catch (\Exception $e) {
            Log::error("❌ OpenVPN connectivity test failed for server {$server->name}: " . $e->getMessage());
            $results['details']['error'] = $e->getMessage();
        }

        return $results;
    }
}
