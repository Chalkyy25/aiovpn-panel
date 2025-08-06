<?php

namespace App\Jobs;

use App\Models\VpnServer;
use App\Models\VpnUser;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class AddWireGuardPeer implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected VpnUser $vpnUser;
    protected ?object $server = null;

    /**
     * Create a new job instance.
     *
     * @param VpnUser $vpnUser
     * @param object|null $server
     */
    public function __construct(VpnUser $vpnUser, object $server = null)
    {
        $this->vpnUser = $vpnUser->load('vpnServers');
        $this->server = $server;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info("🔧 Adding WireGuard peer for user: {$this->vpnUser->username}");

        // Validate user has required WireGuard keys
        $userKeys = $this->vpnUser->only(['wireguard_public_key', 'wireguard_address']);
        if (empty($userKeys['wireguard_public_key']) || empty($userKeys['wireguard_address'])) {
            Log::error("❌ [WG] Missing WireGuard keys for {$this->vpnUser->username}, cannot add peer.");
            return;
        }

        // If a specific server is provided, add peer only to that server
        if ($this->server) {
            $success = $this->addPeerToServer($this->server, $userKeys);

            // Log final status for single server case
            if ($success) {
                Log::info("✅ Completed WireGuard peer setup for user: {$this->vpnUser->username} on server: {$this->server->name}");
            } else {
                Log::error("❌ WireGuard peer setup failed for user: {$this->vpnUser->username} on server: {$this->server->name}");
            }

            return;
        }

        // Otherwise, add peer to all servers associated with the user
        if ($this->vpnUser->vpnServers->isEmpty()) {
            Log::warning("⚠️ No VPN servers associated with user {$this->vpnUser->username}");
            return;
        }

        $successCount = 0;
        $totalServers = $this->vpnUser->vpnServers->count();

        foreach ($this->vpnUser->vpnServers as $server) {
            $success = $this->addPeerToServer($server, $userKeys);
            if ($success) {
                $successCount++;
            }
        }

        $allSuccessful = ($successCount === $totalServers);
        $failedServers = $totalServers - $successCount;

        // Log completion message regardless of whether all servers succeeded
        Log::info("✅ Completed WireGuard peer setup for user: {$this->vpnUser->username}");

        // If there were failures, log them explicitly
        if (!$allSuccessful) {
            Log::warning("⚠️ WireGuard peer setup had partial failures for user: {$this->vpnUser->username} - Failed on $failedServers/$totalServers servers");
        }
    }

    /**
     * Add WireGuard peer to a specific server.
     *
     * @param object $server
     * @param array $userKeys
     * @return bool Whether the operation was successful
     */
    protected function addPeerToServer(object $server, array $userKeys): bool
    {
        Log::info("🔧 Adding WireGuard peer to server: $server->name ($server->ip_address)");

        // Create peer configuration
        $peerConfig = [
            'PublicKey' => $userKeys['wireguard_public_key'],
            'AllowedIPs' => $userKeys['wireguard_address'],
        ];

        // Make sure AllowedIPs has correct format and doesn't have double /32 suffix
        if (!str_contains($peerConfig['AllowedIPs'], '/')) {
            $peerConfig['AllowedIPs'] .= '/32';
        } elseif (str_ends_with($peerConfig['AllowedIPs'], '/32/32')) {
            // Fix duplicate /32 suffix if it exists
            $peerConfig['AllowedIPs'] = str_replace('/32/32', '/32', $peerConfig['AllowedIPs']);
        }

        // Add peer to server
        $result = $this->executeRemoteCommand(
            $server->ip_address,
            $this->buildAddPeerCommand($peerConfig)
        );

        if ($result['status'] !== 0) {
            Log::error("❌ [WG] Failed to add peer for {$this->vpnUser->username} on $server->name");
            $errorMsg = !empty($result['output']) ? implode("\n", $result['output']) : "SSH command failed with status code {$result['status']}";
            Log::error("Error: " . $errorMsg);
            return false;
        }

        Log::info("✅ [WG] Successfully added peer for {$this->vpnUser->username} on $server->name");
        return true;
    }

    /**
     * Build the WireGuard command to add a peer.
     *
     * @param array $peerConfig
     * @return string
     */
    private function buildAddPeerCommand(array $peerConfig): string
    {
        $interface = 'wg0'; // Default WireGuard interface
        $commands = ["wg set $interface"];

        // Add peer with public key and allowed IPs
        $commands[] = "peer {$peerConfig['PublicKey']}";
        $commands[] = "allowed-ips {$peerConfig['AllowedIPs']}";

        // Join commands with spaces
        $wgCommand = implode(' ', $commands);

        // Save configuration permanently
        $saveCommand = "wg-quick save $interface";

        return "$wgCommand && $saveCommand";
    }

    /**
     * Execute a command on a remote server via SSH.
     *
     * @param string $ip
     * @param string $command
     * @return array
     */
    private function executeRemoteCommand(string $ip, string $command): array
    {
        // Find the server by IP address
        $server = VpnServer::where('ip_address', $ip)->first();

        if ($server) {
            // Use the server's getSshCommand method to get the proper SSH command
            $sshBaseCommand = $server->getSshCommand();
            // Add the command to execute
            $sshCommand = "$sshBaseCommand '$command' 2>&1";
        } else {
            // Fallback to default SSH settings if server not found
            // Use absolute path for SSH key and validate it exists
            $sshKey = storage_path('app/ssh_keys/id_rsa');

            // Verify SSH key exists
            if (!file_exists($sshKey)) {
                Log::error("❌ SSH key not found at path: $sshKey");
                return [
                    'status' => 255,
                    'output' => ["SSH key not found at: $sshKey"]
                ];
            }

            $sshUser = 'root';
            // Add error output redirection to capture stderr
            $sshCommand = "ssh -i $sshKey -o StrictHostKeyChecking=no -o ConnectTimeout=30 -p 22 $sshUser@$ip '$command' 2>&1";

            // Log warning about using default SSH settings
            Log::warning("⚠️ [WG] Using default SSH settings for IP: $ip - server not found in database");
        }

        // Use proc_open for better error handling
        $descriptorspec = [
            0 => ["pipe", "r"],  // stdin
            1 => ["pipe", "w"],  // stdout
            2 => ["pipe", "w"]   // stderr
        ];

        $process = proc_open($sshCommand, $descriptorspec, $pipes);

        if (is_resource($process)) {
            // Close stdin
            fclose($pipes[0]);

            // Read stdout
            $output = stream_get_contents($pipes[1]);
            fclose($pipes[1]);

            // Read stderr
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[2]);

            // Get exit code
            $status = proc_close($process);

            // Combine stdout and stderr if needed
            $outputArray = [];
            if (!empty($output)) {
                $outputArray = explode("\n", trim($output));
            }

            // Always capture stderr if it exists, regardless of status code
            if (!empty($stderr)) {
                $outputArray[] = "STDERR: " . $stderr;
            }

            // If command failed, always add detailed error information
            if ($status !== 0) {
                // If output array is empty, add error message based on status code
                if (empty($outputArray)) {
                    if ($status === 255) {
                        $outputArray[] = "SSH connection failed. Possible causes: server unreachable, authentication failure, or connection timeout.";
                    } elseif ($status === 127) {
                        $outputArray[] = "Command not found on remote server. Check if WireGuard is properly installed.";
                    } elseif ($status === 126) {
                        $outputArray[] = "Permission denied when executing command on remote server.";
                    } else {
                        $outputArray[] = "SSH command failed with status code $status. Check server connectivity and WireGuard configuration.";
                    }
                }

                // Add command details for debugging (with sensitive info redacted)
                $redactedCommand = preg_replace('/-i\s+\S+/', '-i [REDACTED]', $sshCommand);
                $outputArray[] = "Command attempted: $redactedCommand";
            }

            return [
                'status' => $status,
                'output' => $outputArray,
            ];
        }

        // If proc_open failed
        return [
            'status' => 255,
            'output' => ['Failed to execute SSH command: could not start process'],
        ];
    }
}
