<?php

namespace App\Jobs;

use App\Models\VpnServer;
use App\Models\VpnUser;
use App\Models\VpnUserConnection;
use App\Traits\ExecutesRemoteCommands;
use Carbon\Carbon;
use Exception;
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
        Log::info("🔍 Checking connections for server: $server->name ($server->ip_address)");

        try {
            $statusLog = $this->fetchOpenVpnStatusLog($server);

            if (empty($statusLog)) {
                Log::warning("⚠️ Could not fetch status log from $server->name");
                $this->markAllUsersDisconnected($server);
                return;
            }

            $connectedUsers = $this->parseStatusLog($statusLog);
            $this->updateConnectionsInDatabase($server, $connectedUsers);

        } catch (Exception $e) {
            Log::error("❌ Error updating connections for $server->name: " . $e->getMessage());
            $this->markAllUsersDisconnected($server);
        }
    }

    /**
     * Fetch OpenVPN status log from server.
     */
    protected function fetchOpenVpnStatusLog(VpnServer $server): string
    {
        $statusPath = '/var/log/openvpn-status.log';

        $result = $this->executeRemoteCommand(
            $server->ip_address,
            "cat $statusPath"
        );

        if ($result['status'] !== 0) {
            Log::error("❌ Failed to fetch status log from $server->name: " . implode("\n", $result['output']));
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

        // Modern OpenVPN: lines start with CLIENT_LIST
        if (str_starts_with($line, 'CLIENT_LIST,')) {
            $parts = explode(',', $line);

            if (count($parts) >= 5) {
                $username = trim($parts[1]); // Modern: username is in 2nd position (double check your real log!)
                // Some logs: username at $parts[1], some at $parts[8], you may need to adjust!
                $realAddress = trim($parts[2]);
                $bytesReceived = (int) trim($parts[5]);
                $bytesSent = (int) trim($parts[6]);
                $connectedSince = trim($parts[7]);
                $clientIp = explode(':', $realAddress)[0];

                $connectedAt = null;
                try {
                    $connectedAt = Carbon::createFromFormat('Y-m-d H:i:s', $connectedSince);
                } catch (Exception) {}

                $connectedUsers[$username] = [
                    'client_ip' => $clientIp,
                    'bytes_received' => $bytesReceived,
                    'bytes_sent' => $bytesSent,
                    'connected_at' => $connectedAt,
                ];
            }
        }
    }

    Log::info("📊 Found " . count($connectedUsers) . " connected users on server (modern parser)");
    return $connectedUsers;
}

    /**
     * Update connections in database.
     */
    protected function updateConnectionsInDatabase(VpnServer $server, array $connectedUsers): void
{
    $serverUsers = $server->vpnUsers()->get();

    foreach ($serverUsers as $user) {
        $conn = VpnUserConnection::firstOrCreate([
            'vpn_user_id'   => $user->id,
            'vpn_server_id' => $server->id,
        ]);

        if (isset($connectedUsers[$user->username])) {
            $u = $connectedUsers[$user->username];

            // 👉 Only set connected_at on transition offline -> online
            if (!$conn->is_connected) {
                $conn->connected_at = $u['connected_at'] ?? now();
                $conn->disconnected_at = null;
            }

            $conn->is_connected   = true;
            $conn->client_ip      = $u['client_ip']      ?? $conn->client_ip;
            $conn->bytes_received = $u['bytes_received'] ?? $conn->bytes_received;
            $conn->bytes_sent     = $u['bytes_sent']     ?? $conn->bytes_sent;
            $conn->save();

            // Optional global flags
            if (!$user->is_online) {
                $user->is_online = true;
            }
            $user->last_seen_at = now();
            $user->last_ip      = $conn->client_ip;
            $user->save();

        } else {
            // Transition online -> offline
            if ($conn->is_connected) {
                $conn->is_connected    = false;
                $conn->disconnected_at = now();
                $conn->save();
            }

            // If no other active connections, mark user offline
            VpnUserConnection::updateUserOnlineStatusIfNoActiveConnections($user->id);
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
            VpnUserConnection::updateUserOnlineStatusIfNoActiveConnections($connection->vpn_user_id);
        }
    }
}
