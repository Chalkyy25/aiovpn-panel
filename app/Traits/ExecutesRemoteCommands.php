<?php

namespace App\Traits;

use App\Models\VpnServer;
use Illuminate\Support\Facades\Log;

trait ExecutesRemoteCommands
{
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

            // Create a temporary directory for SSH operations to avoid permission issues
            $tempSshDir = storage_path('app/temp_ssh');
            if (!is_dir($tempSshDir)) {
                mkdir($tempSshDir, 0700, true);
            }

            $sshUser = 'root';
            // Add error output redirection to capture stderr and use custom known_hosts file
            $sshCommand = "ssh -i $sshKey -o StrictHostKeyChecking=no -o ConnectTimeout=30 -o UserKnownHostsFile=$tempSshDir/known_hosts -p 22 $sshUser@$ip '$command' 2>&1";

            // Log warning about using default SSH settings
            Log::warning("⚠️ Using default SSH settings for IP: $ip - server not found in database");
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
                        $outputArray[] = "Command not found on remote server. Check if the required tools are properly installed.";
                    } elseif ($status === 126) {
                        $outputArray[] = "Permission denied when executing command on remote server.";
                    } else {
                        $outputArray[] = "SSH command failed with status code $status. Check server connectivity and configuration.";
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
