<?php

namespace App\Jobs;

use App\Models\VpnServer;
use App\Models\VpnUser;
use App\Models\VpnUserConnection;
use App\Services\OpenVpnStatusParser;
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
    try {
        $raw = $this->fetchOpenVpnStatusLog($server);

        if ($raw === '') {
            \Log::warning("⚠️ No status file found on {$server->name}");
            $this->markAllUsersDisconnected($server);
            return;
        }

        $parsed = OpenVpnStatusParser::parse($raw); // auto v2/v3
        $connectedUsers = [];

        foreach ($parsed['clients'] as $c) {
            $connectedUsers[$c['username']] = [
                'client_ip'      => $c['client_ip'] ?? null,
                'bytes_received' => (int)($c['bytes_received'] ?? 0),
                'bytes_sent'     => (int)($c['bytes_sent'] ?? 0),
                'connected_at'   => isset($c['connected_at']) ? \Carbon\Carbon::createFromTimestamp($c['connected_at']) : null,
            ];
        }

        $this->updateConnectionsInDatabase($server, $connectedUsers);
    } catch (\Throwable $e) {
        \Log::error("❌ Error updating {$server->name}: ".$e->getMessage());
        $this->markAllUsersDisconnected($server);
    }
}
    /**
     * Fetch OpenVPN status log from server.
     */
    protected function fetchOpenVpnStatusLog(VpnServer $server): string
{
    // Try v3 default then v2 legacy
    $candidates = [
        '/run/openvpn/server.status',   // systemd default
        '/var/log/openvpn-status.log',  // older setups
    ];

    foreach ($candidates as $path) {
        $result = $this->executeRemoteCommand(
            $server->ip_address,
            'test -r '.escapeshellarg($path).' && cat '.escapeshellarg($path).' || echo "__NOFILE__"'
        );

        if (($result['status'] ?? 1) === 0) {
            $out = implode("\n", $result['output'] ?? []);
            if (strpos($out, '__NOFILE__') === false && trim($out) !== '') {
                // Found a readable status file
                return $out;
            }
        }
    }

    return '';
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

                if (count($parts) >= 5) {
                    $username = trim($parts[1]); // Adjust index if needed based on actual log
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
                        'client_ip'      => $clientIp,
                        'bytes_received' => $bytesReceived,
                        'bytes_sent'     => $bytesSent,
                        'connected_at'   => $connectedAt,
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

                if (!$conn->is_connected) {
                    $conn->connected_at = $u['connected_at'] ?? now();
                    $conn->disconnected_at = null;
                }

                $conn->is_connected   = true;
                $conn->client_ip      = $u['client_ip']      ?? $conn->client_ip;
                $conn->bytes_received = $u['bytes_received'] ?? $conn->bytes_received;
                $conn->bytes_sent     = $u['bytes_sent']     ?? $conn->bytes_sent;
                $conn->save();

                if (!$user->is_online) {
                    $user->is_online = true;
                }
                $user->last_ip = $conn->client_ip;
                $user->save();

            } else {
                if ($conn->is_connected) {
                    $conn->is_connected    = false;
                    $conn->disconnected_at = now();
                    $conn->save();
                }

                VpnUserConnection::updateUserOnlineStatusIfNoActiveConnections($user->id);
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

        foreach ($connections as $conn) {
            $conn->update([
                'is_connected'    => false,
                'disconnected_at' => now(),
            ]);

            VpnUserConnection::updateUserOnlineStatusIfNoActiveConnections($conn->vpn_user_id);
        }
    }
}