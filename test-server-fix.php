<?php

// This script tests if the server filtering and SSH key path handling work correctly

require __DIR__ . '/vendor/autoload.php';

// Bootstrap Laravel
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\VpnServer;
use Illuminate\Support\Facades\Log;

// Check all servers in the database
echo "Checking all servers in the database...\n";
$allServers = VpnServer::all();

echo "Found " . $allServers->count() . " servers in total:\n";
foreach ($allServers as $server) {
    echo "- ID: {$server->id}, Name: {$server->name}, IP: {$server->ip_address}, Status: {$server->deployment_status}\n";
}

// Test server filtering
echo "\nTesting server filtering...\n";
$allowedIps = ['5.22.212.177', '83.136.254.231'];
$filteredServers = VpnServer::whereIn('ip_address', $allowedIps)->get();

echo "Found " . $filteredServers->count() . " servers with the specified IP addresses:\n";
foreach ($filteredServers as $server) {
    echo "- {$server->name} ({$server->ip_address})\n";
}

// Test SSH key path handling
echo "\nTesting SSH key path handling...\n";
$possiblePaths = [
    '/var/www/aiovpn/storage/app/ssh_keys/id_rsa',
    storage_path('app/ssh_keys/id_rsa'),
    base_path('storage/app/ssh_keys/id_rsa'),
    base_path('storage/ssh_keys/id_rsa')
];

foreach ($possiblePaths as $path) {
    echo "Checking if SSH key exists at: {$path}\n";
    if (is_file($path)) {
        echo "✅ SSH key found at: {$path}\n";
    } else {
        echo "❌ SSH key not found at: {$path}\n";
    }
}

// Check if any servers have missing IP addresses
echo "\nChecking for servers with missing IP addresses...\n";
$serversWithoutIp = VpnServer::whereNull('ip_address')->orWhere('ip_address', '')->get();

if ($serversWithoutIp->count() > 0) {
    echo "Found " . $serversWithoutIp->count() . " servers with missing IP addresses:\n";
    foreach ($serversWithoutIp as $server) {
        echo "- {$server->id}: {$server->name}\n";
    }
} else {
    echo "No servers with missing IP addresses found.\n";
}

echo "\nTest completed.\n";
