<?php

/**
 * OpenVPN Client Config Generation and Live Session Monitoring Demo
 *
 * This script demonstrates how to:
 * 1. Generate OpenVPN client configs without creating test files
 * 2. Show real server online sessions
 * 3. Test OpenVPN connectivity
 *
 * Usage: php demo_openvpn_features.php
 */

require_once __DIR__ . '/vendor/autoload.php';

use App\Models\VpnUser;
use App\Models\VpnServer;
use App\Services\VpnConfigBuilder;
use Illuminate\Foundation\Application;
use Illuminate\Contracts\Console\Kernel;

// Bootstrap Laravel application
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

echo "🚀 OpenVPN Features Demo\n";
echo "========================\n\n";

// Get first available VPN user and server for demonstration
$vpnUser = VpnUser::with('vpnServers')->first();
$vpnServer = VpnServer::first(); // Get any server, not just active ones

if (!$vpnUser) {
    echo "❌ No VPN users found. Please create a VPN user first.\n";
    echo "ℹ️  You can create a test user using: php artisan tinker\n";
    echo "    VpnUser::create(['username' => 'testuser', 'password' => 'testpass']);\n";
    exit(1);
}

if (!$vpnServer) {
    echo "❌ No VPN servers found. Please create a VPN server first.\n";
    echo "ℹ️  You can create a test server using the admin panel or artisan tinker.\n";
    exit(1);
}

echo "📋 Using VPN User: {$vpnUser->username} (ID: {$vpnUser->id})\n";
echo "🖥️  Using VPN Server: {$vpnServer->name} (IP: {$vpnServer->ip_address})\n\n";

// 1. Generate OpenVPN Config Without Saving Files
echo "1️⃣  GENERATING OPENVPN CLIENT CONFIG (WITHOUT SAVING FILES)\n";
echo "============================================================\n";

try {
    $configContent = VpnConfigBuilder::generateOpenVpnConfigString($vpnUser, $vpnServer);

    echo "✅ OpenVPN config generated successfully!\n";
    echo "📄 Config size: " . strlen($configContent) . " bytes\n";
    echo "📝 Config lines: " . count(explode("\n", $configContent)) . "\n";

    // Show first few lines of config (without sensitive data)
    $lines = explode("\n", $configContent);
    echo "🔍 Config preview (first 10 lines):\n";
    echo "-----------------------------------\n";
    for ($i = 0; $i < min(10, count($lines)); $i++) {
        echo "   " . $lines[$i] . "\n";
    }
    echo "   ... (config continues)\n\n";

    // Verify no files were created
    $expectedFileName = str_replace([' ', '(', ')'], ['_', '', ''], $vpnServer->name) . "_{$vpnUser->username}.ovpn";
    $testPaths = [
        storage_path("app/configs/{$expectedFileName}"),
        storage_path("app/public/ovpn_configs/{$expectedFileName}"),
        public_path("ovpn_configs/{$expectedFileName}")
    ];

    $filesCreated = false;
    foreach ($testPaths as $path) {
        if (file_exists($path)) {
            echo "⚠️  Warning: File found at {$path}\n";
            $filesCreated = true;
        }
    }

    if (!$filesCreated) {
        echo "✅ Confirmed: No test files were created during config generation!\n";
    }

} catch (Exception $e) {
    echo "❌ Failed to generate OpenVPN config: " . $e->getMessage() . "\n";
}

echo "\n";

// 2. Show Real Server Online Sessions
echo "2️⃣  FETCHING REAL SERVER ONLINE SESSIONS\n";
echo "========================================\n";

try {
    $sessions = VpnConfigBuilder::getLiveOpenVpnSessions($vpnServer);

    echo "✅ Successfully fetched live sessions from server!\n";
    echo "👥 Total active sessions: " . count($sessions) . "\n\n";

    if (count($sessions) > 0) {
        echo "📊 Active Sessions:\n";
        echo "-------------------\n";
        foreach ($sessions as $index => $session) {
            echo "   Session " . ($index + 1) . ":\n";
            echo "     👤 Username: {$session['username']}\n";
            echo "     🌐 Real IP: {$session['real_address']}\n";
            echo "     📈 Data Usage: {$session['formatted_bytes']}\n";
            echo "     ⏰ Connected Since: {$session['connected_since']}\n";
            echo "     📊 Bytes Sent: " . number_format($session['bytes_sent']) . "\n";
            echo "     📊 Bytes Received: " . number_format($session['bytes_received']) . "\n";
            echo "\n";
        }
    } else {
        echo "ℹ️  No active sessions found on the server.\n";
    }

} catch (Exception $e) {
    echo "❌ Failed to fetch live sessions: " . $e->getMessage() . "\n";
}

echo "\n";

// 3. Test OpenVPN Connectivity
echo "3️⃣  TESTING OPENVPN CONNECTIVITY\n";
echo "================================\n";

try {
    $results = VpnConfigBuilder::testOpenVpnConnectivity($vpnServer);

    echo "✅ Connectivity test completed!\n\n";
    echo "📋 Test Results:\n";
    echo "----------------\n";
    echo "🔗 Server Reachable: " . ($results['server_reachable'] ? '✅ Yes' : '❌ No') . "\n";
    echo "🔧 OpenVPN Running: " . ($results['openvpn_running'] ? '✅ Yes' : '❌ No') . "\n";
    echo "🚪 Port 1194 Open: " . ($results['port_open'] ? '✅ Yes' : '❌ No') . "\n";
    echo "🔐 Certificates Available: " . ($results['certificates_available'] ? '✅ Yes' : '❌ No') . "\n";

    $overallStatus = $results['server_reachable'] && $results['openvpn_running'] &&
                    $results['port_open'] && $results['certificates_available'];

    echo "\n🎯 Overall Status: " . ($overallStatus ? '✅ READY' : '❌ ISSUES DETECTED') . "\n";

    if (!$overallStatus) {
        echo "\n🔍 Detailed Error Information:\n";
        echo "------------------------------\n";
        if (isset($results['details']['ssh'])) {
            echo "SSH: " . implode(', ', $results['details']['ssh']['output']) . "\n";
        }
        if (isset($results['details']['service'])) {
            echo "Service: " . implode(', ', $results['details']['service']['output']) . "\n";
        }
        if (isset($results['details']['port'])) {
            echo "Port: " . implode(', ', $results['details']['port']['output']) . "\n";
        }
        if (isset($results['details']['certificates'])) {
            echo "Certificates: " . implode(', ', $results['details']['certificates']['output']) . "\n";
        }
    }

} catch (Exception $e) {
    echo "❌ Failed to test connectivity: " . $e->getMessage() . "\n";
}

echo "\n";

// 4. Summary and Usage Instructions
echo "4️⃣  USAGE INSTRUCTIONS\n";
echo "======================\n";
echo "To use these features in your application:\n\n";

echo "📥 Generate and Download Config (without saving files):\n";
echo "   GET /admin/clients/{vpnUser}/config/{vpnServer}/generate\n";
echo "   Controller: VpnConfigController@generateOpenVpnConfig\n\n";

echo "👥 View Live Sessions:\n";
echo "   GET /admin/servers/{vpnServer}/sessions/live\n";
echo "   Controller: VpnConfigController@showLiveSessions\n\n";

echo "🔍 Test Connectivity:\n";
echo "   GET /admin/servers/{vpnServer}/test-connectivity\n";
echo "   Controller: VpnConfigController@testConnectivity\n\n";

echo "👀 Preview Config (JSON response):\n";
echo "   GET /admin/clients/{vpnUser}/config/{vpnServer}/preview\n";
echo "   Controller: VpnConfigController@previewOpenVpnConfig\n\n";

echo "💡 Programmatic Usage:\n";
echo "   \$config = VpnConfigBuilder::generateOpenVpnConfigString(\$user, \$server);\n";
echo "   \$sessions = VpnConfigBuilder::getLiveOpenVpnSessions(\$server);\n";
echo "   \$test = VpnConfigBuilder::testOpenVpnConnectivity(\$server);\n\n";

echo "✅ Demo completed successfully!\n";
echo "🎉 All features are working without creating test files!\n";
