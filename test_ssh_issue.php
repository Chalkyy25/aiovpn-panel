<?php

require_once 'vendor/autoload.php';

use App\Models\VpnServer;
use App\Jobs\UpdateVpnConnectionStatus;
use Illuminate\Support\Facades\Log;

// Bootstrap Laravel
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "🔍 Testing SSH connection issues...\n";

// Get servers from database
$servers = VpnServer::all();

if ($servers->isEmpty()) {
    echo "❌ No servers found in database\n";
    exit(1);
}

foreach ($servers as $server) {
    echo "\n📡 Testing server: {$server->name} ({$server->ip_address})\n";

    // Test getSshCommand method
    $sshCommand = $server->getSshCommand();
    echo "🔧 SSH Command: $sshCommand\n";

    // Test the actual command that would be executed
    $statusPath = '/var/log/openvpn-status.log';
    $fullCommand = "$sshCommand 'cat $statusPath' 2>&1";
    echo "📋 Full command: $fullCommand\n";

    // Execute and capture output
    exec($fullCommand, $output, $returnCode);

    echo "📊 Return code: $returnCode\n";
    echo "📄 Output:\n";
    foreach ($output as $line) {
        echo "  $line\n";
    }

    // Clear output for next iteration
    $output = [];
}

echo "\n✅ Test completed\n";
