<?php

namespace App\Jobs;

use App\Models\VpnServer;
use Illuminate\Support\Facades\Log;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;

class DeployVpnToServer implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $server;

    public function __construct(VpnServer $server)
    {
        $this->server = $server;
    }

    public function handle(): void
    {
        $server = $this->server;

        $server->update([
            'deployment_status' => 'running',
            'deployment_log' => "🚀 Starting deployment to {$server->ip}...",
        ]);

        try {
            $script = $server->protocol === 'wireguard'
                ? '/var/www/scripts/install-wireguard.sh'
                : '/var/www/scripts/install-openvpn.sh';

            $sshKeyPath = storage_path('ssh/id_rsa');
            $sshUser = $server->ssh_username ?? 'root';
            $sshPort = $server->ssh_port ?? 22;

            $command = sprintf(
                'ssh -p %d -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -i %s %s@%s "bash -s" < %s',
                $sshPort,
                $sshKeyPath,
                $sshUser,
                $server->ip,
                $script
            );

            $process = Process::fromShellCommandline($command);
            $process->setTimeout(600);

            $process->run(function ($type, $buffer) use ($server) {
                $server->refresh();
                $server->appendLog(trim($buffer));
            });

            if (!$process->isSuccessful()) {
                throw new ProcessFailedException($process);
            }

            $server->update([
                'deployment_status' => 'success',
                'deployment_log' => $server->deployment_log . "\n✅ Deployment completed successfully.",
            ]);

        } catch (\Exception $e) {
            $server->update([
                'deployment_status' => 'failed',
                'deployment_log' => $server->deployment_log . "\n❌ Error: " . $e->getMessage(),
            ]);

            Log::error("VPN deployment failed for server {$server->ip}: " . $e->getMessage());
        }
    }
}
