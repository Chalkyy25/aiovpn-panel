<?php

namespace App\Traits;

use App\Models\VpnServer;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

trait ExecutesRemoteCommands
{
    /**
     * Execute an SSH command on a VPN server.
     *
     * Returns only STDOUT in 'output' (array of lines). STDERR is kept separate
     * so warnings can never pollute file content (e.g., <ca>/<tls-auth> blocks).
     */
    public function executeRemoteCommand(
        VpnServer $server,
        string $command,
        int $timeoutSeconds = 15
    ): array {
        $host = $server->ssh_host ?? $server->hostname ?? $server->ip_address;
        $user = $server->ssh_user ?? 'root';
        $port = (int)($server->ssh_port ?? 22);

        // Prefer per-server key path, else config, else storage default
        $keyPath = $server->ssh_key_path
            ?? config('services.vpn.ssh_key_path')
            ?? storage_path('app/ssh_keys/id_rsa');

        if (empty($host)) {
            return ['status' => 1, 'output' => [], 'stderr' => ['Server host/IP missing']];
        }
        if (! is_file($keyPath)) {
            Log::error("❌ SSH key missing", ['path' => $keyPath, 'server' => $host]);
            return ['status' => 1, 'output' => [], 'stderr' => ["SSH key missing at {$keyPath}"]];
        }

        // Build argv-style command (no shell), quiet + no prompts, no known_hosts writes
        $ssh = [
            'ssh',
            '-i', $keyPath,
            '-p', (string) $port,
            '-q',                              // be quiet on STDERR for non-errors
            '-o', 'BatchMode=yes',             // no password prompts
            '-o', 'StrictHostKeyChecking=no',  // trust host (we're in infra)
            '-o', 'UserKnownHostsFile=/dev/null',
            '-o', 'LogLevel=ERROR',            // suppress “Permanently added …”
            sprintf('%s@%s', $user, $host),
            $command,
        ];

        $proc = new Process($ssh, null, null, null, $timeoutSeconds);

        try {
            $proc->run();
        } catch (\Throwable $e) {
            Log::error('❌ SSH exec exception', [
                'server' => $host,
                'port'   => $port,
                'cmd'    => $command,
                'error'  => $e->getMessage(),
            ]);

            return [
                'status' => 255,
                'output' => [],
                'stderr' => [$e->getMessage()],
            ];
        }

        // Split outputs into arrays of lines (do NOT merge stderr into output)
        $stdout = trim($proc->getOutput());
        $stderr = trim($proc->getErrorOutput());

        return [
            'status' => $proc->getExitCode() ?? 1,
            'output' => $stdout === '' ? [] : preg_split("/\r\n|\n|\r/", $stdout),
            'stderr' => $stderr === '' ? [] : preg_split("/\r\n|\n|\r/", $stderr),
        ];
    }
}