<?php

require_once 'vendor/autoload.php';

// Bootstrap Laravel
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\VpnServer;
use App\Services\VpnConfigBuilder;

// Test the stealth config generation
echo "🧪 Testing Generic Stealth Config Generation\n\n";

// Create a mock server object
$mockServer = new class {
    public $id = 1;
    public $name = "UK-London-Stealth";
    public $hostname = "uk-london.aiovpn.com";
    public $country = "United Kingdom";
    public $city = "London";
    public $server_type = "openvpn";
    public $ca_cert = "-----BEGIN CERTIFICATE-----\nMIIC2DCCAcCgAwIBAgIUExample...\n-----END CERTIFICATE-----";
    public $client_cert = "-----BEGIN CERTIFICATE-----\nMIIC1jCCAb4CAQAwDQYJKoZIhvcNAQELBQAwFTETMBEGA1UEAwwKRXhhbXBsZUNB...\n-----END CERTIFICATE-----";
    public $client_key = "-----BEGIN PRIVATE KEY-----\nMIIEvQIBADANBgkqhkiG9w0BAQEFAASCBKcwggSjAgEAAoIBAQC8Example...\n-----END PRIVATE KEY-----";
    public $ta_key = "-----BEGIN OpenVPN Static key V1-----\n6acef03f62675b4b1bbd03e53c187727\nExample...\n-----END OpenVPN Static key V1-----";
};

try {
    $configBuilder = new VpnConfigBuilder();
    $config = $configBuilder->generateGenericStealthConfig($mockServer);
    
    echo "✅ Generic stealth config generated successfully!\n\n";
    echo "📄 Config preview (first 20 lines):\n";
    echo str_repeat("=", 50) . "\n";
    
    $lines = explode("\n", $config);
    for ($i = 0; $i < min(20, count($lines)); $i++) {
        echo ($i + 1) . ": " . $lines[$i] . "\n";
    }
    
    if (count($lines) > 20) {
        echo "... (" . (count($lines) - 20) . " more lines)\n";
    }
    
    echo str_repeat("=", 50) . "\n";
    echo "📊 Config stats:\n";
    echo "  • Total lines: " . count($lines) . "\n";
    echo "  • Size: " . strlen($config) . " bytes\n";
    echo "  • Contains TCP 443: " . (strpos($config, 'remote') !== false && strpos($config, '443 tcp') !== false ? '✅' : '❌') . "\n";
    echo "  • Contains AES-128-GCM: " . (strpos($config, 'AES-128-GCM') !== false ? '✅' : '❌') . "\n";
    echo "  • Contains auth-nocache: " . (strpos($config, 'auth-nocache') !== false ? '✅' : '❌') . "\n";
    
    echo "\n🎯 Perfect for AIO Smarters mobile app distribution!\n";
    
} catch (\Exception $e) {
    echo "❌ Error generating config: " . $e->getMessage() . "\n";
    echo "📍 Stack trace:\n" . $e->getTraceAsString() . "\n";
}