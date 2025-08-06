<?php

require_once 'vendor/autoload.php';

use Illuminate\Foundation\Application;
use App\Jobs\UpdateVpnConnectionStatus;
use App\Models\VpnServer;
use App\Models\VpnUser;
use App\Models\VpnUserConnection;

// Bootstrap Laravel
$app = new Application(
    $_ENV['APP_BASE_PATH'] ?? dirname(__DIR__)
);

$app->singleton(
    Illuminate\Contracts\Http\Kernel::class,
    App\Http\Kernel::class
);

$app->singleton(
    Illuminate\Contracts\Console\Kernel::class,
    App\Console\Kernel::class
);

$app->singleton(
    Illuminate\Contracts\Debug\ExceptionHandler::class,
    App\Exceptions\Handler::class
);

$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "🔄 Testing VPN Status Update System\n";
echo "=====================================\n\n";

// Check if we have any active servers
$servers = VpnServer::where('deployment_status', 'active')->get();
echo "📊 Found " . $servers->count() . " active servers:\n";
foreach ($servers as $server) {
    echo "  - {$server->name} ({$server->ip_address})\n";
}
echo "\n";

// Check if we have any VPN users
$users = VpnUser::count();
echo "👥 Total VPN users: {$users}\n";

$onlineUsers = VpnUser::where('is_online', true)->count();
echo "🟢 Currently online users: {$onlineUsers}\n";

$activeConnections = VpnUserConnection::where('is_connected', true)->count();
echo "🔗 Active connections: {$activeConnections}\n\n";

// Test the job
echo "🚀 Running VPN status update job...\n";
try {
    $job = new UpdateVpnConnectionStatus();
    $job->handle();
    echo "✅ Job completed successfully!\n\n";
} catch (Exception $e) {
    echo "❌ Job failed: " . $e->getMessage() . "\n\n";
}

// Check results after job
$onlineUsersAfter = VpnUser::where('is_online', true)->count();
echo "📈 Online users after update: {$onlineUsersAfter}\n";

$activeConnectionsAfter = VpnUserConnection::where('is_connected', true)->count();
echo "📈 Active connections after update: {$activeConnectionsAfter}\n";

// Show recent connections
echo "\n🔍 Recent connection activity:\n";
$recentConnections = VpnUserConnection::with(['vpnUser', 'vpnServer'])
    ->orderBy('updated_at', 'desc')
    ->limit(5)
    ->get();

foreach ($recentConnections as $connection) {
    $status = $connection->is_connected ? '🟢 Connected' : '🔴 Disconnected';
    $user = $connection->vpnUser->username ?? 'Unknown';
    $server = $connection->vpnServer->name ?? 'Unknown';
    $time = $connection->updated_at->format('Y-m-d H:i:s');
    echo "  {$status} - {$user} on {$server} at {$time}\n";
}

echo "\n✅ Test completed!\n";
echo "You can now visit /admin/vpn-dashboard to see the real-time dashboard.\n";
