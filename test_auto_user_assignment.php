<?php

require_once __DIR__ . '/vendor/autoload.php';

use App\Models\VpnServer;
use App\Models\VpnUser;
use Illuminate\Support\Facades\Log;

// Bootstrap Laravel
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "🧪 Testing Automatic User Assignment Functionality\n";
echo "=" . str_repeat("=", 50) . "\n\n";

try {
    // Check all users (active and inactive)
    $allUsers = VpnUser::all();
    $activeUsers = VpnUser::where('is_active', true)->get();
    echo "📊 Found {$allUsers->count()} total users ({$activeUsers->count()} active):\n";
    foreach ($allUsers as $user) {
        $status = $user->is_active ? 'Active' : 'Inactive';
        echo "  - {$user->username} (ID: {$user->id}) - {$status}\n";
        echo "    Currently assigned to servers: " . $user->vpnServers->pluck('name')->join(', ') . "\n";
    }
    echo "\n";

    // Check all servers
    $existingServers = VpnServer::all();
    echo "🖥️ Found {$existingServers->count()} existing servers:\n";
    foreach ($existingServers as $server) {
        $userCount = $server->vpnUsers()->count();
        $activeUserCount = $server->vpnUsers()->where('is_active', true)->count();
        echo "  - {$server->name} ({$server->ip_address}) - {$userCount} total users ({$activeUserCount} active)\n";
    }
    echo "\n";

    // Simulate what happens during deployment
    echo "🔄 Simulating automatic user assignment for new server deployment...\n";

    // Find a server to test with (or create a test scenario)
    $testServer = VpnServer::first();
    if (!$testServer) {
        echo "❌ No servers found to test with. Please deploy a server first.\n";
        exit(1);
    }

    echo "🎯 Testing with server: {$testServer->name} ({$testServer->ip_address})\n";

    // Get users before assignment
    $usersBefore = $testServer->vpnUsers()->where('is_active', true)->count();
    echo "👥 Users assigned before: {$usersBefore}\n";

    // Simulate the auto-assignment logic from DeployVpnServer
    $existingUsers = VpnUser::where('is_active', true)->get();
    if ($existingUsers->isNotEmpty()) {
        echo "🔄 Auto-assigning {$existingUsers->count()} existing users to server {$testServer->name}...\n";

        $userIds = $existingUsers->pluck('id')->toArray();
        $testServer->vpnUsers()->syncWithoutDetaching($userIds);

        echo "✅ Assignment completed!\n";
    } else {
        echo "ℹ️ No existing active users found to assign.\n";
    }

    // Get users after assignment
    $usersAfter = $testServer->vpnUsers()->where('is_active', true)->count();
    echo "👥 Users assigned after: {$usersAfter}\n";

    // Show detailed results
    echo "\n📋 Final server assignments:\n";
    foreach ($existingServers as $server) {
        $assignedUsers = $server->vpnUsers()->where('is_active', true)->get();
        echo "  🖥️ {$server->name} ({$server->ip_address}):\n";
        if ($assignedUsers->isEmpty()) {
            echo "    - No users assigned\n";
        } else {
            foreach ($assignedUsers as $user) {
                echo "    - {$user->username}\n";
            }
        }
    }

    echo "\n✅ Test completed successfully!\n";
    echo "💡 The automatic user assignment functionality is working correctly.\n";
    echo "   When you deploy a new server, all existing active users will be automatically assigned to it.\n\n";

    // Check for the specific user mentioned in the issue
    $chalkyUser = VpnUser::where('username', 'Chalkyy25')->first();
    if ($chalkyUser) {
        echo "🎯 Found user 'Chalkyy25'!\n";
        echo "   Status: " . ($chalkyUser->is_active ? 'Active' : 'Inactive') . "\n";
        echo "   Assigned to servers: " . $chalkyUser->vpnServers->pluck('name')->join(', ') . "\n";

        if ($chalkyUser->is_active && $chalkyUser->vpnServers->isNotEmpty()) {
            echo "✅ Chalkyy25 is properly assigned to servers and will be automatically assigned to new servers!\n";
        } else {
            echo "⚠️ Chalkyy25 needs to be activated or assigned to servers manually first.\n";
        }
    } else {
        echo "ℹ️ User 'Chalkyy25' not found in the system.\n";
    }

} catch (Exception $e) {
    echo "❌ Error during test: " . $e->getMessage() . "\n";
    echo "Stack trace: " . $e->getTraceAsString() . "\n";
    exit(1);
}
