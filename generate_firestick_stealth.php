<?php

echo "📺 Generating Firestick & Android TV Optimized Stealth Configs\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

// Create output directory
$outputDir = __DIR__ . '/firestick-stealth-configs';
if (!is_dir($outputDir)) {
    mkdir($outputDir, 0755, true);
    echo "📁 Created directory: firestick-stealth-configs/\n\n";
}

// Use existing template
$templateFile = null;
$possibleTemplates = ['aio-uk.ovpn', 'aio.ovpn', 'aio-default.ovpn', 'uk-london.ovpn'];

foreach ($possibleTemplates as $template) {
    if (file_exists($template)) {
        $templateFile = $template;
        break;
    }
}

if (!$templateFile) {
    echo "❌ No template .ovpn file found!\n";
    exit(1);
}

echo "📄 Using template: {$templateFile}\n";
$templateContent = file_get_contents($templateFile);

// Extract certificates from template
preg_match('/<ca>(.*?)<\/ca>/s', $templateContent, $caMatches);
preg_match('/<tls-auth>(.*?)<\/tls-auth>/s', $templateContent, $taMatches);

if (empty($caMatches[1]) || empty($taMatches[1])) {
    echo "❌ Could not extract certificates from template file!\n";
    exit(1);
}

$caCert = trim($caMatches[1]);
$tlsAuth = trim($taMatches[1]);

echo "✅ Extracted certificates from template\n\n";

// Firestick/Android TV optimized servers with your real IPs
$servers = [
    ['name' => 'Germany-Firestick-Stealth', 'ip' => '5.22.212.177', 'location' => 'Frankfurt, Germany'],
    ['name' => 'UK-Firestick-Stealth', 'ip' => '83.136.254.231', 'location' => 'London, United Kingdom'],
    ['name' => 'Spain-Firestick-Stealth', 'ip' => '5.22.218.134', 'location' => 'Madrid, Spain'],
];

echo "� Generating Firestick stealth configs with your real server IPs!\n";
echo "📺 Optimized for Firestick & Android TV devices with Unbound DNS\n\n";

foreach ($servers as $server) {
    $name = $server['name'];
    $ip = $server['ip'];
    $location = $server['location'];
    
    // Skip placeholder IPs
    if (strpos($ip, 'YOUR_') !== false) {
        echo "⏭️  Skipping {$name} - Please replace {$ip} with real IP\n";
        continue;
    }
    
    // Generate Firestick/Android TV optimized config
    $config = generateFirestickStealthConfig($name, $ip, $location, $caCert, $tlsAuth);
    
    // Save config
    $filename = "Firestick_Stealth_{$name}.ovpn";
    file_put_contents("{$outputDir}/{$filename}", $config);
    
    echo "✅ Generated: {$filename}\n";
    echo "   📍 Location: {$location}\n";
    echo "   📺 Optimized for: Firestick/Android TV\n";
    echo "   🌐 Endpoint: {$ip}:443 (TCP Stealth)\n\n";
}

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "📁 All Firestick configs saved to: firestick-stealth-configs/\n\n";

echo "📺 Firestick & Android TV Optimizations:\n";
echo "   ✅ Longer timeouts for slower ARM processors\n";
echo "   ✅ Reduced verbosity for limited logging\n";
echo "   ✅ Optimized buffer sizes for streaming\n";
echo "   ✅ DNS settings for better IPTV performance\n";
echo "   ✅ TCP keep-alive for stable connections\n";
echo "   ✅ Memory-efficient settings\n\n";

echo "🎯 Installation on Firestick:\n";
echo "   1. Sideload OpenVPN for Android TV\n";
echo "   2. Transfer .ovpn files via USB or cloud\n";
echo "   3. Import config in OpenVPN app\n";
echo "   4. Enter VPN credentials\n";
echo "   5. Connect and enjoy stealth streaming!\n\n";

echo "🚀 Ready for Firestick deployment!\n";

/**
 * Generate Firestick/Android TV optimized stealth config
 */
function generateFirestickStealthConfig(string $name, string $ip, string $location, string $caCert, string $tlsAuth): string
{
    return <<<OVPN
# === AIOVPN • {$name} (Firestick Stealth Mode) ===
# Location: {$location}
# Optimized for Amazon Firestick & Android TV devices
# TCP 443 stealth - bypasses ISP blocks for streaming

client
dev tun
proto tcp
remote {$ip} 443
resolv-retry infinite
nobind
persist-key
persist-tun
remote-cert-tls server
auth SHA256
auth-user-pass
auth-nocache
verb 2

# Firestick/Android TV Optimizations
connect-retry 2
connect-retry-max 5
connect-timeout 10
server-poll-timeout 8
resolv-retry-max 3

# Streaming optimized timeouts
ping 15
ping-restart 60
ping-timer-rem

# Memory efficient settings for ARM devices
fast-io
sndbuf 393216
rcvbuf 393216

# DNS settings for your private Unbound server (10.66.66.1)
dhcp-option DNS 10.66.66.1

# TCP keep-alive for stable streaming connections
socket-flags TCP_NODELAY
keepalive 10 60

# Modern cipher suite (Android TV compatible)
data-ciphers AES-128-GCM:CHACHA20-POLY1305:AES-256-GCM
data-ciphers-fallback AES-128-GCM
cipher AES-128-GCM
pull-filter ignore "cipher"
pull-filter ignore "auth"

# Prevent DNS leaks - force use of your Unbound DNS
block-outside-dns

<ca>
{$caCert}
</ca>

<tls-auth>
{$tlsAuth}
</tls-auth>
key-direction 1

OVPN;
}