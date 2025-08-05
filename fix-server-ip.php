<?php

// This script fixes the server data in the database by updating the IP addresses
// of two servers and deleting the rest

require __DIR__ . '/vendor/autoload.php';

// Bootstrap Laravel
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\VpnServer;
use Illuminate\Support\Facades\DB;

echo "Starting server data fix...\n";

// Begin a transaction to ensure all operations succeed or fail together
DB::beginTransaction();

try {
    // Get all servers
    $servers = VpnServer::all();
    echo "Found " . $servers->count() . " servers in total.\n";

    // Keep track of which servers to keep
    $germanyServer = null;
    $ukServer = null;

    // Find the first two servers to update
    foreach ($servers as $server) {
        if (!$germanyServer) {
            $germanyServer = $server;
            continue;
        }

        if (!$ukServer) {
            $ukServer = $server;
            break;
        }
    }

    // Update the Germany server
    if ($germanyServer) {
        echo "Updating Germany server (ID: {$germanyServer->id})...\n";
        $germanyServer->update([
            'name' => 'Germany',
            'ip_address' => '5.22.212.177',
            'deployment_status' => 'succeeded', // Set to succeeded to make buttons work
        ]);
        echo "✅ Germany server updated.\n";
    } else {
        echo "❌ No server found to update as Germany server.\n";
    }

    // Update the UK London server
    if ($ukServer) {
        echo "Updating UK London server (ID: {$ukServer->id})...\n";
        $ukServer->update([
            'name' => 'UK London',
            'ip_address' => '83.136.254.231',
            'deployment_status' => 'succeeded', // Set to succeeded to make buttons work
        ]);
        echo "✅ UK London server updated.\n";
    } else {
        echo "❌ No server found to update as UK London server.\n";
    }

    // Delete all other servers
    $serversToDelete = VpnServer::whereNotIn('id', [
        $germanyServer ? $germanyServer->id : 0,
        $ukServer ? $ukServer->id : 0,
    ])->get();

    echo "Deleting " . $serversToDelete->count() . " extra servers...\n";
    foreach ($serversToDelete as $server) {
        echo "Deleting server ID: {$server->id}, Name: {$server->name}...\n";
        $server->delete();
    }
    echo "✅ Extra servers deleted.\n";

    // Commit the transaction
    DB::commit();
    echo "✅ All changes committed to the database.\n";

} catch (\Exception $e) {
    // Rollback the transaction if anything fails
    DB::rollBack();
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo "❌ All changes have been rolled back.\n";
}

echo "\nServer data fix completed.\n";

// Verify the changes
echo "\nVerifying changes...\n";
$updatedServers = VpnServer::all();
echo "Found " . $updatedServers->count() . " servers after fix:\n";
foreach ($updatedServers as $server) {
    echo "- ID: {$server->id}, Name: {$server->name}, IP: {$server->ip_address}, Status: {$server->deployment_status}\n";
}

echo "\nFix completed.\n";
