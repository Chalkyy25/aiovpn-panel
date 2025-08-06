<?php

namespace App\Jobs;

use App\Models\VpnUser;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class RemoveWireGuardPeer implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected VpnUser $vpnUser;
    protected $server = null;

    /**
     * Create a new job instance.
     *
     * @param VpnUser $vpnUser
     * @param object|null $server
     */
    public function __construct(VpnUser $vpnUser, $server = null)
    {
        $this->vpnUser = $vpnUser->load('vpnServers');
        $this->server = $server;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info("🔧 Removing WireGuard peer for user: {$this->vpnUser->username}");

        // Validate user has WireGuard public key
        if (empty($this->vpnUser->wireguard_public_key)) {
            Log::error("❌ [WG] Missing WireGuard public key for {$this->vpnUser->username}, cannot remove peer.");
            return;
        }

        // If a specific server is provided, remove peer only from that server
        if ($this->server) {
            $this->removePeerFromServer($this->server);
            return;
        }

        // Otherwise, remove peer from all servers associated with the user
        if ($this->vpnUser->vpnServers->isEmpty()) {
            Log::warning("⚠️ No VPN servers associated with user {$this->vpnUser->username}");
            return;
        }

        foreach ($this->vpnUser->vpnServers as $server) {
            $this->removePeerFromServer($server);
        }

        Log::info("✅ Completed WireGuard peer removal for user: {$this->vpnUser->username}");
    }

    /**
     * Remove WireGuard peer from a specific server.
     *
     * @param object $server
     * @return void
     */
    protected function removePeerFromServer($server): void
    {
        Log::info("🔧 Removing WireGuard peer from server: {$server->name} ({$server->ip_address})");

        // Remove peer from server
        $result = $this->executeRemoteCommand(
            $server->ip_address,
            $this->buildRemovePeerCommand($this->vpnUser->wireguard_public_key)
        );

        if ($result['status'] !== 0) {
            Log::error("❌ [WG] Failed to remove peer for {$this->vpnUser->username} from {$server->name}");
            Log::error("Error: " . implode("\n", $result['output']));
            return;
        }

        Log::info("✅ [WG] Successfully removed peer for {$this->vpnUser->username} from {$server->name}");
    }

    /**
     * Build the WireGuard command to remove a peer.
     *
     * @param string $publicKey
     * @return string
     */
    private function buildRemovePeerCommand(string $publicKey): string
    {
        $interface = 'wg0'; // Default WireGuard interface

        // Command to remove peer by public key
        $wgCommand = "wg set $interface peer $publicKey remove";

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
        $server = \App\Models\VpnServer::where('ip_address', $ip)->first();

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
                \Illuminate\Support\Facades\Log::error("❌ SSH key not found at path: $sshKey");
                return [
                    'status' => 255,
                    'output' => ["SSH key not found at: $sshKey"]
                ];
            }

            $sshUser = 'root';
            // Add error output redirection to capture stderr
            $sshCommand = "ssh -i $sshKey -o StrictHostKeyChecking=no -o ConnectTimeout=30 -p 22 $sshUser@$ip '$command' 2>&1";

            // Log warning about using default SSH settings
            \Illuminate\Support\Facades\Log::warning("⚠️ [WG] Using default SSH settings for IP: $ip - server not found in database");
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
