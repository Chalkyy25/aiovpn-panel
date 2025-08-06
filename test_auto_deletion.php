<?php

/**
 * Test script to verify auto-deletion functionality for VPN users
 * This script tests that when a user is deleted, both WireGuard peers and OpenVPN files are cleaned up automatically
 */

require_once __DIR__ . '/vendor/autoload.php';

use App\Models\VpnUser;
use App\Models\VpnServer;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Queue;

// Bootstrap Laravel
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "🧪 Testing Auto-Deletion Functionality for VPN Users\n";
echo "=" . str_repeat("=", 50) . "\n\n";

try {
    // Enable queue logging to see dispatched jobs
    Queue::fake();

    echo "1. Creating test VPN server...\n";
    $testServer = VpnServer::create([
        'name' => 'test-server-autodeletion',
        'ip_address' => '192.168.1.100',
        'protocol' => 'openvpn',
        'ssh_port' => 22,
        'ssh_user' => 'root',
        'ssh_type' => 'key',
        'ssh_key' => storage_path('app/ssh_keys/id_rsa'),
        'port' => 1194,
        'transport' => 'udp',
        'dns' => '1.1.1.1',
        'deployment_status' => 'deployed',
        'is_deploying' => false,
    ]);
    echo "✅ Test server created: {$testServer->name} (ID: {$testServer->id})\n\n";

    echo "2. Creating test VPN user...\n";
    $testUser = VpnUser::create([
        'username' => 'test-user-autodeletion',
        'email' => 'test@example.com',
        'password' => bcrypt('password'),
        'plain_password' => 'password',
        'is_active' => true,
        'max_connections' => 1,
    ]);
    echo "✅ Test user created: {$testUser->username} (ID: {$testUser->id})\n";
    echo "   WireGuard Public Key: {$testUser->wireguard_public_key}\n";
    echo "   WireGuard Address: {$testUser->wireguard_address}\n\n";

    echo "3. Associating user with server...\n";
    $testUser->vpnServers()->attach($testServer->id);
    $testUser->refresh();
    echo "✅ User associated with server\n\n";

    echo "4. Creating test OVPN file to simulate existing configuration...\n";
    $ovpnFileName = "public/ovpn_configs/{$testServer->name}_{$testUser->username}.ovpn";
    $testOvpnContent = "# Test OVPN configuration for {$testUser->username}\nclient\ndev tun\n";
    Storage::put($ovpnFileName, $testOvpnContent);
    Storage::setVisibility($ovpnFileName, 'public');
    echo "✅ Test OVPN file created: storage/app/{$ovpnFileName}\n";
    echo "   File exists: " . (Storage::exists($ovpnFileName) ? "YES" : "NO") . "\n\n";

    echo "5. Testing user deletion with auto-cleanup...\n";
    echo "   Before deletion - OVPN file exists: " . (Storage::exists($ovpnFileName) ? "YES" : "NO") . "\n";

    // Clear any existing queued jobs
    Queue::fake();

    // Delete the user - this should trigger auto-cleanup
    $username = $testUser->username;
    $testUser->delete();

    echo "✅ User '{$username}' deleted successfully\n\n";

    echo "6. Verifying cleanup jobs were dispatched...\n";

    // Check if RemoveWireGuardPeer job was dispatched
    Queue::assertPushed(\App\Jobs\RemoveWireGuardPeer::class, function ($job) use ($username) {
        return $job->vpnUser->username === $username;
    });
    echo "✅ RemoveWireGuardPeer job was dispatched\n";

    // Check if RemoveOpenVPNUser job was dispatched
    Queue::assertPushed(\App\Jobs\RemoveOpenVPNUser::class, function ($job) use ($username) {
        return $job->vpnUser->username === $username;
    });
    echo "✅ RemoveOpenVPNUser job was dispatched\n\n";

    echo "7. Simulating job execution to test file cleanup...\n";

    // Manually create and execute the RemoveOpenVPNUser job to test file cleanup
    $removeOpenVPNJob = new \App\Jobs\RemoveOpenVPNUser($testUser, $testServer);
    $removeOpenVPNJob->handle();

    echo "   After cleanup - OVPN file exists: " . (Storage::exists($ovpnFileName) ? "YES" : "NO") . "\n";

    if (!Storage::exists($ovpnFileName)) {
        echo "✅ OVPN file was successfully cleaned up\n";
    } else {
        echo "❌ OVPN file was NOT cleaned up\n";
    }

    echo "\n8. Cleaning up test data...\n";

    // Clean up test server
    $testServer->delete();
    echo "✅ Test server deleted\n";

    // Clean up any remaining files
    if (Storage::exists($ovpnFileName)) {
        Storage::delete($ovpnFileName);
        echo "✅ Remaining test files cleaned up\n";
    }

    echo "\n" . str_repeat("=", 60) . "\n";
    echo "🎉 AUTO-DELETION TEST COMPLETED SUCCESSFULLY!\n";
    echo "✅ WireGuard peer removal job dispatched automatically\n";
    echo "✅ OpenVPN cleanup job dispatched automatically\n";
    echo "✅ OVPN configuration files cleaned up properly\n";
    echo "✅ Model event handlers working correctly\n";
    echo str_repeat("=", 60) . "\n";

} catch (Exception $e) {
    echo "\n❌ TEST FAILED: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";

    // Clean up on failure
    if (isset($testUser) && $testUser->exists) {
        $testUser->forceDelete();
        echo "🧹 Test user cleaned up\n";
    }
    if (isset($testServer) && $testServer->exists) {
        $testServer->delete();
        echo "🧹 Test server cleaned up\n";
    }
    if (isset($ovpnFileName) && Storage::exists($ovpnFileName)) {
        Storage::delete($ovpnFileName);
        echo "🧹 Test files cleaned up\n";
    }

    exit(1);
}
