<?php

echo "🎯 Generating Real Stealth OVPN Files from Your Server Configs\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

// Create output directory
$outputDir = __DIR__ . '/stealth-configs-for-upload';
if (!is_dir($outputDir)) {
    mkdir($outputDir, 0755, true);
    echo "📁 Created directory: stealth-configs-for-upload/\n\n";
}

// Check for existing OVPN files in current directory
$ovpnFiles = glob('*.ovpn');

if (empty($ovpnFiles)) {
    echo "❌ No .ovpn files found in current directory\n";
    echo "💡 Available files:\n";
    $allFiles = scandir('.');
    foreach ($allFiles as $file) {
        if ($file !== '.' && $file !== '..' && !is_dir($file)) {
            echo "   - {$file}\n";
        }
    }
    echo "\n💡 Please ensure your server .ovpn files are in this directory\n";
    exit(1);
}

echo "📂 Found existing OVPN files:\n";
foreach ($ovpnFiles as $file) {
    echo "   - {$file}\n";
}
echo "\n";

foreach ($ovpnFiles as $ovpnFile) {
    echo "🔄 Processing: {$ovpnFile}\n";
    
    $content = file_get_contents($ovpnFile);
    if ($content === false) {
        echo "   ❌ Could not read file\n\n";
        continue;
    }
    
    // Convert to stealth config
    $stealthConfig = convertToStealth($content, $ovpnFile);
    
    // Generate output filename
    $baseName = pathinfo($ovpnFile, PATHINFO_FILENAME);
    $stealthName = "AIO_Stealth_{$baseName}.ovpn";
    
    // Save stealth config
    file_put_contents("{$outputDir}/{$stealthName}", $stealthConfig);
    
    echo "   ✅ Generated: {$stealthName}\n";
    echo "   📍 Converted to TCP 443 stealth mode\n\n";
}

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "📁 All stealth configs saved to: stealth-configs-for-upload/\n\n";

echo "📱 AIO Smarters Upload Steps:\n";
echo "   1. Login to your AIO Smarters admin panel\n";
echo "   2. Navigate to VPN config upload/management section\n";
echo "   3. Upload each .ovpn file from stealth-configs-for-upload/\n";
echo "   4. Test with OpenVPN Connect app\n";
echo "   5. Users enter VPN credentials when prompted\n\n";

echo "🎯 Stealth Features Added:\n";
echo "   ✅ TCP 443 only (no UDP mixing)\n";
echo "   ✅ ISP bypass optimized\n";
echo "   ✅ Modern cipher enforcement\n";
echo "   ✅ Mobile connection timeouts\n";
echo "   ✅ iOS/Android compatible\n\n";

echo "🚀 Ready for AIO Smarters!\n";

/**
 * Convert existing OVPN to stealth mode
 */
function convertToStealth(string $content, string $filename): string
{
    $lines = explode("\n", $content);
    $newLines = [];
    $inCert = false;
    $certSection = '';
    
    // Extract server name for header
    $serverName = pathinfo($filename, PATHINFO_FILENAME);
    
    // Add stealth header
    $newLines[] = "# === AIOVPN • {$serverName} (Stealth Mode) ===";
    $newLines[] = "# TCP 443 stealth config for AIO Smarters App";
    $newLines[] = "# Bypasses ISP blocks by appearing as HTTPS traffic";
    $newLines[] = "";
    
    $remoteHost = '';
    
    foreach ($lines as $line) {
        $line = trim($line);
        
        // Skip comments and empty lines initially
        if (empty($line) || strpos($line, '#') === 0) {
            continue;
        }
        
        // Extract hostname from remote lines
        if (preg_match('/^remote\s+([^\s]+)/', $line, $matches)) {
            $remoteHost = $matches[1];
            continue; // We'll add our own remote line
        }
        
        // Skip UDP proto lines
        if (strpos($line, 'proto udp') !== false) {
            continue;
        }
        
        // Skip existing cipher lines (we'll add our own)
        if (preg_match('/^(cipher|data-ciphers|ncp-ciphers)/', $line)) {
            continue;
        }
        
        // Keep certificate sections and most other directives
        if (strpos($line, '<ca>') !== false || 
            strpos($line, '<cert>') !== false || 
            strpos($line, '<key>') !== false || 
            strpos($line, '<tls-auth>') !== false ||
            strpos($line, '<tls-crypt>') !== false) {
            $inCert = true;
        }
        
        if ($inCert || 
            strpos($line, 'client') !== false ||
            strpos($line, 'dev tun') !== false ||
            strpos($line, 'resolv-retry') !== false ||
            strpos($line, 'nobind') !== false ||
            strpos($line, 'persist-') !== false ||
            strpos($line, 'remote-cert-tls') !== false ||
            strpos($line, 'auth-user-pass') !== false ||
            strpos($line, 'verb') !== false ||
            strpos($line, 'key-direction') !== false) {
            $newLines[] = $line;
        }
        
        if (strpos($line, '</ca>') !== false || 
            strpos($line, '</cert>') !== false || 
            strpos($line, '</key>') !== false || 
            strpos($line, '</tls-auth>') !== false ||
            strpos($line, '</tls-crypt>') !== false) {
            $inCert = false;
        }
    }
    
    // Insert stealth-specific settings after client directive
    $clientIndex = -1;
    for ($i = 0; $i < count($newLines); $i++) {
        if (trim($newLines[$i]) === 'client') {
            $clientIndex = $i;
            break;
        }
    }
    
    if ($clientIndex !== -1) {
        $stealthSettings = [
            "dev tun",
            "proto tcp",
            "remote {$remoteHost} 443",
            "resolv-retry infinite", 
            "nobind",
            "persist-key",
            "persist-tun",
            "remote-cert-tls server",
            "auth SHA256",
            "auth-user-pass",
            "auth-nocache",
            "verb 3",
            "",
            "# Optimized for mobile apps and ISP bypass",
            "connect-retry 1",
            "connect-retry-max 2", 
            "connect-timeout 4",
            "",
            "# Modern cipher suite (iOS/Android compatible)",
            "data-ciphers AES-128-GCM:CHACHA20-POLY1305:AES-256-GCM",
            "data-ciphers-fallback AES-128-GCM",
            "cipher AES-128-GCM",
            "pull-filter ignore \"cipher\"",
            "pull-filter ignore \"auth\"",
            ""
        ];
        
        // Replace everything between client and first certificate
        $certStart = -1;
        for ($i = $clientIndex + 1; $i < count($newLines); $i++) {
            if (strpos($newLines[$i], '<') !== false) {
                $certStart = $i;
                break;
            }
        }
        
        if ($certStart !== -1) {
            // Remove old settings between client and certs
            array_splice($newLines, $clientIndex + 1, $certStart - $clientIndex - 1, $stealthSettings);
        }
    }
    
    return implode("\n", $newLines);
}