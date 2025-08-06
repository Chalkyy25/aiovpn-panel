<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\VpnUser;
use Illuminate\Support\Facades\Log;

// Test WireGuard key generation
Log::info("🧪 Testing WireGuard key generation");

// Generate keys
$keys = VpnUser::generateWireGuardKeys();

// Check if keys were generated
if (!empty($keys['private']) && !empty($keys['public'])) {
    echo "✅ WireGuard keys generated successfully:\n";
    echo "Private key: " . substr($keys['private'], 0, 10) . "...\n";
    echo "Public key: " . substr($keys['public'], 0, 10) . "...\n";
} else {
    echo "❌ Failed to generate WireGuard keys\n";
}

// Test creating a VPN user with WireGuard keys
echo "\n🧪 Testing VPN user creation with WireGuard keys\n";

try {
    $user = new VpnUser();
    $user->username = 'test-user-' . time();
    $user->plain_password = 'password123';
    $user->password = bcrypt('password123');
    $user->save();

    echo "✅ VPN user created successfully: {$user->username}\n";
    echo "WireGuard private key: " . substr($user->wireguard_private_key, 0, 10) . "...\n";
    echo "WireGuard public key: " . substr($user->wireguard_public_key, 0, 10) . "...\n";
    echo "WireGuard address: {$user->wireguard_address}\n";

    // Clean up - delete the test user
    $user->delete();
    echo "🧹 Test user deleted\n";
} catch (\Exception $e) {
    echo "❌ Error creating VPN user: " . $e->getMessage() . "\n";
}

echo "\n✅ Test completed\n";
