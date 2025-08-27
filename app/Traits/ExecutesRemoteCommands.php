<?php

namespace App\Traits;

use App\Models\VpnServer;
use Illuminate\Support\Facades\Log;

trait ExecutesRemoteCommands
{
    /**
     * Execute a command on a remote VPN server via SSH.
     *
     * Uses the panel-managed key at storage/app/ssh_keys/id_rsa.
     */
    private function executeRemoteCommand(VpnServer $server, string $command): array
    {
        $user = $server->ssh_user ?? 'root';
        $port = $server->ssh_port ?? 22;
        $ip   = $server->ip_address;

        // 🔑 Always use Laravel-managed key
        $keyPath = storage_path('app/ssh_keys/id_rsa');

        if (!file_exists($keyPath)) {
            Log::error("❌ SSH key missing at {$keyPath}");
            return ['status' => 1, 'output' => ["SSH key missing at {$keyPath}"]];
        }

        // Build SSH command
        $sshCommand = sprintf(
            'ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -i %s -p %d %s@%s %s 2>&1',
            escapeshellarg($keyPath),
            $port,
            escapeshellarg($user),
            escapeshellarg($ip),
            escapeshellarg($command)
        );

        $descriptorspec = [
            0 => ["pipe", "r"],
            1 => ["pipe", "w"],
            2 => ["pipe", "w"],
        ];

        $process = proc_open($sshCommand, $descriptorspec, $pipes);

        if (!is_resource($process)) {
            return ['status' => 255, 'output' => ['Failed to start SSH process']];
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
        $status = proc_close($process);

        $output = [];
        if ($stdout) {
            $output = array_merge($output, explode("\n", trim($stdout)));
        }
        if ($stderr) {
            $output[] = "STDERR: " . trim($stderr);
        }

        if ($status !== 0) {
            $redacted = preg_replace('/-i\s+\S+/', '-i [REDACTED]', $sshCommand);
            $output[] = "SSH failed with status {$status}. Command: {$redacted}";
        }

        return ['status' => $status, 'output' => $output];
    }
}