<?php

namespace App\Jobs;

use App\Models\VpnServer;
use App\Models\VpnUser;
use App\Models\VpnUserConnection;
use App\Traits\ExecutesRemoteCommands;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class UpdateVpnConnectionStatus implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, ExecutesRemoteCommands;

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info("🔄 Starting VPN connection status update");

        $servers = VpnServer::where('deployment_status', 'succeeded')->get();
        Log::info('Found servers: ' . $servers->count());

        if ($servers->isEmpty()) {
            Log::info("⚠️ No active VPN servers found");
            return;
        }

        foreach ($servers as $server) {
            $this->updateServerConnections($server);
        }

        Log::info("✅ VPN connection status update completed");
    }

    /**
     * Update connections for a specific server.
     */
    protected function updateServerConnections(VpnServer $server): void
    {
        Log::info("🔍 Checking connections for server: {$server->name} ({$server->ip_address})");

        try {
            $statusLog = $this->fetchOpenVpnStatusLog($server);

            if (empty($statusLog)) {
                Log::warning("⚠️ Could not fetch status log from {$server->name}");
                $this->markAllUsersDisconnected($server);
                return;
            }

            $connectedUsers = $this->parseStatusLog($statusLog);
            $this->updateConnectionsInDatabase($server, $connectedUsers);

        } catch (\Exception $e) {
            Log::error("❌ Error updating connections for {$server->name}: " . $e->getMessage());
            $this->markAllUsersDisconnected($server);
        }
    }

    /**
     * Fetch OpenVPN status log from server.
     */
    protected function fetchOpenVpnStatusLog(VpnServer $server): string
    {
        $statusPath = '/etc/openvpn/openvpn-status.log';

        $result = $this->executeRemoteCommand(
            $server->ip_address,
            "cat {$statusPath}"
        );

        if ($result['status'] !== 0) {
            Log::error("❌ Failed to fetch status log from {$server->name}: " . implode("\n", $result['output']));
            return '';
        }

        return implode("\n", $result['output']);
    }

    /**
     * Parse OpenVPN status log to extract connected users.
     */
    protected function parseStatusLog(string $statusLog): array
{
    $connectedUsers = [];
    $lines = explode("\n", $statusLog);

    foreach ($lines as $line) {
        $line = trim($line);
        if (str_starts_with($line, 'CLIENT_LIST,')) {
            $parts = explode(',', $line);

            if (count($parts) >= 12) {
                $username = trim($parts[1]); // or $parts[9] if that's your real username
                $realAddress = trim($parts[2]);
                $bytesReceived = (int) trim($parts[5]);
                $bytesSent = (int) trim($parts[6]);
                $connectedSince = trim($parts[7]);
                $clientIp = explode(':', $realAddress)[0];

                $connectedAt = null;
                try {
                    $connectedAt = \Carbon\Carbon::createFromFormat('Y-m-d H:i:s', $connectedSince);
                } catch (\Exception $e) {}

                $connectedUsers[$username] = [
                    'client_ip' => $clientIp,
                    'bytes_received' => $bytesReceived,
                    'bytes_sent' => $bytesSent,
                    'connected_at' => $connectedAt,
                ];
            }
        }
    }

    \Log::info("📊 Found " . count($connectedUsers) . " connected users on server (modern parser)");
    return $connectedUsers;
}

    /**
     * Update connections in database.
     */
    protected function updateConnectionsInDatabase(VpnServer $server, array $connectedUsers): void
    {
        // Get all users associated with this server
        $serverUsers = $server->vpnUsers()->get();

        foreach ($serverUsers as $user) {
            $connection = VpnUserConnection::firstOrCreate([
                'vpn_user_id' => $user->id,
                'vpn_server_id' => $server->id,
            ]);

            if (isset($connectedUsers[$user->username])) {
                // User is connected
                $userData = $connectedUsers[$user->username];

                $connection->update([
                    'is_connected' => true,
                    'client_ip' => $userData['client_ip'],
                    'connected_at' => $userData['connected_at'] ?? $connection->connected_at ?? now(),
                    'bytes_received' => $userData['bytes_received'],
                    'bytes_sent' => $userData['bytes_sent'],
                    'disconnected_at' => null,
                ]);

                // Update user's global online status
                $user->update([
                    'is_online' => true,
                    'last_seen_at' => now(),
                    'last_ip' => $userData['client_ip'],
                ]);

            } else {
                // User is not connected
                if ($connection->is_connected) {
                    $connection->update([
                        'is_connected' => false,
                        'disconnected_at' => now(),
                    ]);
                }

                // Check if user has any active connections on other servers
                $hasActiveConnections = VpnUserConnection::where('vpn_user_id', $user->id)
                    ->where('is_connected', true)
                    ->exists();

                if (!$hasActiveConnections) {
                    $user->update([
                        'is_online' => false,
                        'last_seen_at' => now(),
                    ]);
                }
            }
        }
    }

    /**
     * Mark all users as disconnected for a server.
     */
    protected function markAllUsersDisconnected(VpnServer $server): void
    {
        $connections = VpnUserConnection::where('vpn_server_id', $server->id)
            ->where('is_connected', true)
            ->get();

        foreach ($connections as $connection) {
            $connection->update([
                'is_connected' => false,
                'disconnected_at' => now(),
            ]);

            // Check if user has any other active connections
            $hasActiveConnections = VpnUserConnection::where('vpn_user_id', $connection->vpn_user_id)
                ->where('is_connected', true)
                ->exists();

            if (!$hasActiveConnections) {
                VpnUser::where('id', $connection->vpn_user_id)->update([
                    'is_online' => false,
                    'last_seen_at' => now(),
                ]);
            }
        }
    }
}
