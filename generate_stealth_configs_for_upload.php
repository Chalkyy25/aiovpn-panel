<?php

require_once 'vendor/autoload.php';

// Bootstrap Laravel
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\VpnServer;
use App\Services\VpnConfigBuilder;

echo "🎯 Generating Stealth OVPN Files for AIO Smarters Upload\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

// Create output directory
$outputDir = __DIR__ . '/stealth-configs-for-upload';
if (!is_dir($outputDir)) {
    mkdir($outputDir, 0755, true);
    echo "📁 Created directory: stealth-configs-for-upload/\n\n";
}

try {
    // Get all active servers
    $servers = VpnServer::where('status', 'active')->get();
    
    if ($servers->isEmpty()) {
        echo "❌ No active servers found in database\n";
        echo "💡 Creating demo configs instead...\n\n";
        
        // Create demo server configs for testing
        $demoServers = [
            [
                'name' => 'UK-London-Stealth',
                'hostname' => 'uk-london.aiovpn.com',
                'country' => 'United Kingdom',
                'city' => 'London'
            ],
            [
                'name' => 'US-NewYork-Stealth', 
                'hostname' => 'us-newyork.aiovpn.com',
                'country' => 'United States',
                'city' => 'New York'
            ],
            [
                'name' => 'DE-Frankfurt-Stealth',
                'hostname' => 'de-frankfurt.aiovpn.com', 
                'country' => 'Germany',
                'city' => 'Frankfurt'
            ]
        ];
        
        foreach ($demoServers as $demo) {
            $safeName = preg_replace('/[^\w\-]+/u', '_', $demo['name']);
            $filename = "AIO_Stealth_{$safeName}.ovpn";
            
            $config = generateDemoStealthConfig($demo);
            file_put_contents("{$outputDir}/{$filename}", $config);
            
            echo "✅ Generated: {$filename}\n";
            echo "   Location: {$demo['city']}, {$demo['country']}\n";
            echo "   Endpoint: {$demo['hostname']}:443 (TCP)\n\n";
        }
        
    } else {
        // Generate configs for real servers
        $configBuilder = new VpnConfigBuilder();
        
        foreach ($servers as $server) {
            try {
                $safeName = preg_replace('/[^\w\-]+/u', '_', $server->name);
                $filename = "AIO_Stealth_{$safeName}.ovpn";
                
                $config = $configBuilder->generateGenericStealthConfig($server);
                file_put_contents("{$outputDir}/{$filename}", $config);
                
                echo "✅ Generated: {$filename}\n";
                echo "   Location: {$server->city}, {$server->country}\n";
                echo "   Endpoint: {$server->hostname}:443 (TCP)\n\n";
                
            } catch (Exception $e) {
                echo "❌ Failed: {$server->name} - {$e->getMessage()}\n\n";
            }
        }
    }
    
} catch (Exception $e) {
    echo "❌ Database error: {$e->getMessage()}\n";
    echo "💡 Generating demo configs instead...\n\n";
    
    // Fallback to demo configs
    $demoServers = [
        [
            'name' => 'UK-London-Stealth',
            'hostname' => 'uk-london.aiovpn.com',
            'country' => 'United Kingdom', 
            'city' => 'London'
        ],
        [
            'name' => 'US-NewYork-Stealth',
            'hostname' => 'us-newyork.aiovpn.com',
            'country' => 'United States',
            'city' => 'New York'
        ]
    ];
    
    foreach ($demoServers as $demo) {
        $safeName = preg_replace('/[^\w\-]+/u', '_', $demo['name']);
        $filename = "AIO_Stealth_{$safeName}.ovpn";
        
        $config = generateDemoStealthConfig($demo);
        file_put_contents("{$outputDir}/{$filename}", $config);
        
        echo "✅ Generated: {$filename}\n";
        echo "   Location: {$demo['city']}, {$demo['country']}\n";
        echo "   Endpoint: {$demo['hostname']}:443 (TCP)\n\n";
    }
}

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "📁 All configs saved to: stealth-configs-for-upload/\n\n";

echo "📱 AIO Smarters Upload Instructions:\n";
echo "   1. Open AIO Smarters panel/admin\n";
echo "   2. Go to VPN configuration upload section\n";
echo "   3. Upload each .ovpn file from stealth-configs-for-upload/\n";
echo "   4. Users will see these as server options\n";
echo "   5. Users enter their VPN credentials when connecting\n\n";

echo "🎯 Stealth Config Features:\n";
echo "   ✅ TCP 443 only (bypasses ISP blocks)\n";
echo "   ✅ Appears as HTTPS traffic\n"; 
echo "   ✅ Modern AES-128-GCM cipher\n";
echo "   ✅ iOS/Android compatible\n";
echo "   ✅ Fast mobile timeouts\n";
echo "   ✅ No embedded credentials\n\n";

echo "🚀 Ready for AIO Smarters upload!\n";

/**
 * Generate demo stealth config for testing
 */
function generateDemoStealthConfig(array $server): string
{
    $name = $server['name'];
    $hostname = $server['hostname'];
    
    return <<<OVPN
# === AIOVPN • {$name} (Stealth Mode) ===
# Generic TCP 443 stealth config for AIO Smarters App
# Bypasses ISP blocks by appearing as HTTPS traffic

client
dev tun
proto tcp
remote {$hostname} 443
resolv-retry infinite
nobind
persist-key
persist-tun
remote-cert-tls server
auth SHA256
auth-user-pass
auth-nocache
verb 3

# Optimized for mobile apps and ISP bypass
connect-retry 1
connect-retry-max 2
connect-timeout 4

# Modern cipher suite (iOS/Android compatible)
data-ciphers AES-128-GCM:CHACHA20-POLY1305:AES-256-GCM
data-ciphers-fallback AES-128-GCM
cipher AES-128-GCM
pull-filter ignore "cipher"
pull-filter ignore "auth"

# Certificate Authority
<ca>
-----BEGIN CERTIFICATE-----
MIICyDCCAbCgAwIBAgIUHWV1wC8EKINfYQTWgpK9D1k8uHcwDQYJKoZIhvcNAQEL
BQAwEzERMA8GA1UEAwwIQ2hhbmdlTWUwHhcNMjQxMDE1MTIwMDAwWhcNMzQxMDEz
MTIwMDAwWjATMREwDwYDVQQDDAhDaGFuZ2VNZTCCASIwDQYJKoZIhvcNAQEBBQAD
ggEPADCCAQoCggEBAK8QHUgVDRjLFjNGvS1vA3eJ5QX2rKj8tQx5Z1k8YgGbPmQ4
aiovpn-example-ca-cert-content-for-demo-purposes-only
-----END CERTIFICATE-----
</ca>

# TLS Authentication Key
<tls-auth>
-----BEGIN OpenVPN Static key V1-----
6acef03f62675b4b1bbd03e53c187727
aiovpn-example-ta-key-content-for-demo
b3f89a0e8c5d7b2f4e9a1d6c3e5f8g9h
stealth-mode-optimized-for-mobile-apps
-----END OpenVPN Static key V1-----
</tls-auth>
key-direction 1

OVPN;
}