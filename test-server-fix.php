<?php

require __DIR__ . '/vendor/autoload.php';

// Bootstrap Laravel
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\VpnServer;
use App\Livewire\Pages\Admin\ServerShow;
use Illuminate\Support\Facades\Log;

echo "Testing the server IP address fix...\n\n";

// Get all servers
$servers = VpnServer::all();
echo "Found " . $servers->count() . " VPN servers in the database.\n\n";

// Test each server
foreach ($servers as $server) {
    echo "Testing server: {$server->name} (ID: {$server->id})\n";
    echo "  IP Address: " . ($server->ip_address ?: 'NULL') . "\n";

    // Create a ServerShow component and mount the server
    $component = new ServerShow();

    // Capture logs
    $logs = [];
    Log::shouldReceive('info')->andReturnUsing(function ($message, $context = []) use (&$logs) {
        $logs[] = ['level' => 'info', 'message' => $message, 'context' => $context];
        return null;
    });

    Log::shouldReceive('error')->andReturnUsing(function ($message, $context = []) use (&$logs) {
        $logs[] = ['level' => 'error', 'message' => $message, 'context' => $context];
        return null;
    });

    Log::shouldReceive('warning')->andReturnUsing(function ($message, $context = []) use (&$logs) {
        $logs[] = ['level' => 'warning', 'message' => $message, 'context' => $context];
        return null;
    });

    // Mount the server
    $component->mount($server);

    // Check if the component has the server with IP address
    if (isset($component->vpnServer) && !empty($component->vpnServer->ip_address)) {
        echo "  ✅ Component has server with IP address: {$component->vpnServer->ip_address}\n";
    } else {
        echo "  ❌ Component does not have server with IP address\n";
    }

    // Print logs
    echo "\n  Logs:\n";
    foreach ($logs as $log) {
        echo "    [{$log['level']}] {$log['message']}\n";
        if (!empty($log['context'])) {
            echo "      Context: " . json_encode($log['context']) . "\n";
        }
    }

    echo "\n";
}

// Test with a server that has no IP address
echo "Testing with a server that has no IP address...\n";
$testServer = new VpnServer();
$testServer->id = 999;
$testServer->name = "Test Server";
$testServer->exists = true;
// Intentionally not setting ip_address

// Create a ServerShow component and mount the server
$component = new ServerShow();

// Capture logs
$logs = [];
Log::shouldReceive('info')->andReturnUsing(function ($message, $context = []) use (&$logs) {
    $logs[] = ['level' => 'info', 'message' => $message, 'context' => $context];
    return null;
});

Log::shouldReceive('error')->andReturnUsing(function ($message, $context = []) use (&$logs) {
    $logs[] = ['level' => 'error', 'message' => $message, 'context' => $context];
    return null;
});

Log::shouldReceive('warning')->andReturnUsing(function ($message, $context = []) use (&$logs) {
    $logs[] = ['level' => 'warning', 'message' => $message, 'context' => $context];
    return null;
});

// Mount the server
$component->mount($testServer);

// Check if the component has the server with IP address
if (isset($component->vpnServer) && !empty($component->vpnServer->ip_address)) {
    echo "  ✅ Component has server with IP address: {$component->vpnServer->ip_address}\n";
} else {
    echo "  ❌ Component does not have server with IP address\n";
}

// Print logs
echo "\n  Logs:\n";
foreach ($logs as $log) {
    echo "    [{$log['level']}] {$log['message']}\n";
    if (!empty($log['context'])) {
        echo "      Context: " . json_encode($log['context']) . "\n";
    }
}

echo "\nTest completed.\n";
