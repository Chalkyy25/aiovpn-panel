<?php

require __DIR__ . '/vendor/autoload.php';

use Illuminate\Support\Facades\Log;
use App\Jobs\AddWireGuardPeer;
use App\Models\VpnUser;
use App\Models\VpnServer;

// Set up logging to console
Log::setDefaultDriver('single');

echo "Testing WireGuard error handling...\n\n";

// Create a test VPN user
$user = new VpnUser();
$user->username = 'TestUser';
$user->wireguard_public_key = 'TestPublicKey';
$user->wireguard_address = '10.8.0.5';

// Create a test server with an invalid IP to force SSH failure
$server = new VpnServer();
$server->name = 'Test Server';
$server->ip_address = '0.0.0.1'; // Invalid IP that will cause SSH to fail
$server->protocol = 'wireguard';

// Create a collection of servers for the user
$servers = collect([$server]);
$user->setRelation('vpnServers', $servers);

// Create and execute the job
$job = new AddWireGuardPeer($user);
$job->handle();

echo "\nTest completed. Check the logs above for error messages.\n";
echo "Expected behavior: SSH command should fail and produce a meaningful error message.\n";
echo "The final log should indicate that the WireGuard peer setup completed with errors.\n";
