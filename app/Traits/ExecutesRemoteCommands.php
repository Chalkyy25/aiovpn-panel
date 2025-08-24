<?php

namespace App\Traits;

use App\Models\VpnServer;
use Illuminate\Support\Facades\Log;

trait ExecutesRemoteCommands
{
    /**
     * Execute a command on a remote server via SSH.
     *
     * @param \App\Models\VpnServer $server
     * @param string $command
     * @return array{status:int,output:array<string>}
     */
    private function executeRemoteCommand(VpnServer $server, string $command): array
    {
        // Always prefer the server model’s own SSH builder
        try {
            $sshBaseCommand = $server->getSshCommand();
        } catch (\Throwable $e) {
            Log::error("❌ getSshCommand() failed for server {$server->name}: ".$e->getMessage());
            return [
                'status' => 255,
                'output' => ["getSshCommand() failed: ".$e->getMessage()],
            ];
        }

        // Build final command
        $sshCommand = "$sshBaseCommand '$command' 2>&1";

        // Use proc_open for better error handling
        $descriptorspec = [
            0 => ["pipe", "r"], // stdin
            1 => ["pipe", "w"], // stdout
            2 => ["pipe", "w"], // stderr
        ];

        $process = proc_open($sshCommand, $descriptorspec, $pipes);

        if (!is_resource($process)) {
            return [
                'status' => 255,
                'output' => ['Failed to execute SSH command: could not start process'],
            ];
        }

        // Close stdin
        fclose($pipes[0]);

        // Read stdout and stderr
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        $status = proc_close($process);

        // Build output array
        $outputArray = [];
        if (!empty($output)) {
            $outputArray = explode("\n", trim($output));
        }
        if (!empty($stderr)) {
            $outputArray[] = "STDERR: ".$stderr;
        }

        if ($status !== 0) {
            if (empty($outputArray)) {
                $outputArray[] = "SSH command failed with status code $status.";
            }
            $redactedCommand = preg_replace('/-i\s+\S+/', '-i [REDACTED]', $sshCommand);
            $outputArray[] = "Command attempted: $redactedCommand";
        }

        return [
            'status' => $status,
            'output' => $outputArray,
        ];
    }
}
