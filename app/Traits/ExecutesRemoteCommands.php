<?php

namespace App\Traits;

use App\Models\VpnServer;
use Illuminate\Support\Facades\Log;

trait ExecutesRemoteCommands
{
    /**
     * Execute a command on a remote server via SSH.
     *
     * Uses baked-in defaults for UpCloud: root + /root/.ssh/id_rsa
     */
    private function executeRemoteCommand(VpnServer $server, string $command): array
    {
        $user = $server->ssh_user ?? 'root';
        $key  = $server->ssh_key ?? '/root/.ssh/id_rsa';
        $port = $server->ssh_port ?? 22;
        $ip   = $server->ip_address;

        // Build SSH command
        $sshCommand = sprintf(
            'ssh -o StrictHostKeyChecking=no -i %s -p %d %s@%s %s 2>&1',
            escapeshellarg($key),
            $port,
            escapeshellarg($user),
            escapeshellarg($ip),
            $command
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
        if ($stdout) $output = array_merge($output, explode("\n", trim($stdout)));
        if ($stderr) $output[] = "STDERR: " . trim($stderr);

        if ($status !== 0) {
            $redacted = preg_replace('/-i\s+\S+/', '-i [REDACTED]', $sshCommand);
            $output[] = "SSH failed with status $status. Command: $redacted";
        }

        return ['status' => $status, 'output' => $output];
    }
}
