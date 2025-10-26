<?php

echo "🌍 Bulk Generate Stealth Configs for All Your Servers\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

// Create output directory
$outputDir = __DIR__ . '/stealth-configs-for-upload';
if (!is_dir($outputDir)) {
    mkdir($outputDir, 0755, true);
}

// Use one of your existing configs as a template
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
    echo "💡 Please ensure you have one of these files in the current directory:\n";
    foreach ($possibleTemplates as $template) {
        echo "   - {$template}\n";
    }
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

// Define your servers here - EDIT THIS SECTION
$servers = [
    // Format: ['name' => 'Server Display Name', 'ip' => 'IP_ADDRESS', 'location' => 'City, Country']
    
    ['name' => 'UK-London-01', 'ip' => 'YOUR_UK_IP_HERE', 'location' => 'London, UK'],
    ['name' => 'US-NewYork-01', 'ip' => 'YOUR_US_IP_HERE', 'location' => 'New York, USA'],
    ['name' => 'DE-Frankfurt-01', 'ip' => 'YOUR_DE_IP_HERE', 'location' => 'Frankfurt, Germany'],
    ['name' => 'CA-Toronto-01', 'ip' => 'YOUR_CA_IP_HERE', 'location' => 'Toronto, Canada'],
    ['name' => 'AU-Sydney-01', 'ip' => 'YOUR_AU_IP_HERE', 'location' => 'Sydney, Australia'],
    
    // Add more servers as needed...
];

echo "🔧 EDIT THE SCRIPT TO ADD YOUR SERVERS!\n";
echo "📍 Open this file and replace the IP addresses in the \$servers array\n\n";

foreach ($servers as $server) {
    $name = $server['name'];
    $ip = $server['ip'];
    $location = $server['location'];
    
    // Skip placeholder IPs
    if (strpos($ip, 'YOUR_') !== false) {
        echo "⏭️  Skipping {$name} - Please replace {$ip} with real IP\n";
        continue;
    }
    
    // Generate stealth config
    $config = generateStealthConfig($name, $ip, $location, $caCert, $tlsAuth);
    
    // Save config
    $filename = "AIO_Stealth_{$name}.ovpn";
    file_put_contents("{$outputDir}/{$filename}", $config);
    
    echo "✅ Generated: {$filename}\n";
    echo "   📍 Location: {$location}\n";
    echo "   🌐 Endpoint: {$ip}:443 (TCP Stealth)\n\n";
}

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "📁 All configs saved to: stealth-configs-for-upload/\n\n";

echo "🎯 Next Steps:\n";
echo "   1. Edit this script and replace YOUR_XX_IP_HERE with real IPs\n";
echo "   2. Run the script again: php bulk_generate_stealth.php\n";
echo "   3. Upload all .ovpn files to AIO Smarters\n";
echo "   4. Test with OpenVPN Connect app\n\n";

echo "🚀 Ready for global stealth VPN deployment!\n";

/**
 * Generate stealth config for a server
 */
function generateStealthConfig(string $name, string $ip, string $location, string $caCert, string $tlsAuth): string
{
    return <<<OVPN
# === AIOVPN • {$name} (Stealth Mode) ===
# Location: {$location}
# TCP 443 stealth config for AIO Smarters App
# Bypasses ISP blocks by appearing as HTTPS traffic

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

<ca>
{$caCert}
</ca>

<tls-auth>
{$tlsAuth}
</tls-auth>
key-direction 1

OVPN;
}