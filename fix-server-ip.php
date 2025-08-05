<?php

require __DIR__ . '/vendor/autoload.php';

// Bootstrap Laravel
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\VpnServer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

echo "Checking for VPN servers with missing IP addresses...\n\n";

// Get all servers
$servers = VpnServer::all();
echo "Found " . $servers->count() . " VPN servers in the database.\n";

// Check for servers with missing IP addresses
$serversWithMissingIp = $servers->filter(function ($server) {
    return empty($server->ip_address);
});

echo "Found " . $serversWithMissingIp->count() . " servers with missing IP addresses.\n";

if ($serversWithMissingIp->count() > 0) {
    echo "\nServers with missing IP addresses:\n";

    foreach ($serversWithMissingIp as $server) {
        echo "ID: {$server->id}, Name: {$server->name}\n";

        // Check if there's an 'ip' field in the database that might have the IP address
        $rawServer = DB::table('vpn_servers')->where('id', $server->id)->first();

        // Debug: Print all fields of the server
        echo "  Raw database fields:\n";
        foreach ((array)$rawServer as $field => $value) {
            echo "    $field: " . ($value ?: 'NULL') . "\n";
        }

        // Ask for a new IP address
        echo "\n  Enter a new IP address for this server (or leave empty to skip): ";
        $newIp = trim(fgets(STDIN));

        if (!empty($newIp)) {
            // Validate IP address
            if (filter_var($newIp, FILTER_VALIDATE_IP)) {
                // Update the server
                $server->ip_address = $newIp;
                $server->save();

                echo "  ✅ Updated server {$server->name} with IP address {$newIp}\n";
                Log::info("Updated server {$server->name} (ID: {$server->id}) with IP address {$newIp}");
            } else {
                echo "  ❌ Invalid IP address: {$newIp}\n";
            }
        } else {
            echo "  ⚠️ Skipped updating server {$server->name}\n";
        }

        echo "\n";
    }

    // Verify the updates
    $remainingServersWithMissingIp = VpnServer::all()->filter(function ($server) {
        return empty($server->ip_address);
    });

    echo "After updates, there are " . $remainingServersWithMissingIp->count() . " servers with missing IP addresses.\n";

    if ($remainingServersWithMissingIp->count() > 0) {
        echo "\nRemaining servers with missing IP addresses:\n";
        foreach ($remainingServersWithMissingIp as $server) {
            echo "ID: {$server->id}, Name: {$server->name}\n";
        }
    }
} else {
    echo "\nAll servers have IP addresses set. No action needed.\n";
}

echo "\nDone.\n";
