<?php

require_once 'vendor/autoload.php';

// Bootstrap Laravel
$app = require_once 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\VpnUser;
use App\Models\VpnServer;
use App\Jobs\RemoveWireGuardPeer;
use Illuminate\Support\Facades\Log;

echo "=== WireGuard Peer Removal Test ===\n";

// Find a test user with WireGuard key (or create one for testing)
$testUser = VpnUser::where('wireguard_public_key', '!=', null)->first();

if (!$testUser) {
    echo "No user with WireGuard key found. Creating test user...\n";

    $testUser = new VpnUser();
    $testUser->username = 'test-wg-removal-' . time();
    $testUser->email = 'test@example.com';
    $testUser->password = bcrypt('password');
    $testUser->save();

    echo "Created test user: {$testUser->username}\n";
}

echo "Test user: {$testUser->username}\n";
echo "WireGuard public key: {$testUser->wireguard_public_key}\n";

// Get associated servers
$servers = $testUser->vpnServers;
echo "Associated servers: " . $servers->count() . "\n";

if ($servers->isEmpty()) {
    echo "No servers associated with user. Please associate the user with a server first.\n";
    exit(1);
}

foreach ($servers as $server) {
    echo "Server: {$server->name} ({$server->ip_address})\n";

    // Test the WireGuard command that would be executed
    $publicKey = $testUser->wireguard_public_key;
    $interface = 'wg0';

    // Current problematic command
    $currentCommand = "wg set $interface peer '$publicKey' remove && wg show $interface peers | grep -q '$publicKey' && echo 'PEER_STILL_EXISTS'; wg-quick save $interface";

    echo "\nCurrent command being executed:\n";
    echo "$currentCommand\n";

    // Test what happens when we run individual parts
    echo "\nTesting individual command parts:\n";
    echo "1. wg set $interface peer '$publicKey' remove\n";
    echo "2. wg show $interface peers | grep -q '$publicKey' && echo 'PEER_STILL_EXISTS'\n";
    echo "3. wg-quick save $interface (THIS IS THE PROBLEM - invalid command)\n";

    echo "\nCorrect commands should be:\n";
    echo "Option 1: wg showconf $interface > /etc/wireguard/$interface.conf\n";
    echo "Option 2: wg-quick down $interface && wg-quick up $interface\n";

    break; // Only test with first server
}

echo "\n=== Test Complete ===\n";
echo "The issue is that 'wg-quick save' is not a valid WireGuard command.\n";
echo "This means peers are removed from memory but not persisted to config.\n";
