<?php

namespace App\Jobs;

use App\Models\VpnServer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class DeployVpnServer implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public VpnServer $vpnServer;

    public function __construct(VpnServer $vpnServer)
    {
        $this->vpnServer = $vpnServer;
    }

    public function handle(): void
    {
        try {
            if ($this->vpnServer->is_deploying) {
                Log::warning("DEPLOY_JOB: Server #{$this->vpnServer->id} is already deploying.");
                return;
            }

            $this->vpnServer->update([
                'is_deploying' => true,
                'deployment_status' => 'running',
                'deployment_log' => "Starting deployment on {$this->vpnServer->ip_address} …\n",
            ]);

            $ip       = $this->vpnServer->ip_address;
            $port     = $this->vpnServer->ssh_port ?? 22;
            $user     = $this->vpnServer->ssh_user;
            $sshType  = $this->vpnServer->ssh_type;
            $password = $this->vpnServer->ssh_password;
            $keyPath  = $this->vpnServer->ssh_key ?? '/var/www/aiovpn/storage/app/ssh_keys/id_rsa';

            if ($sshType === 'key' && !is_file($keyPath)) {
                Log::error("DEPLOY_JOB: SSH key missing at $keyPath");
                $this->vpnServer->update([
                    'is_deploying' => false,
                    'deployment_status' => 'failed',
                    'deployment_log' => "❌ Missing SSH key: {$keyPath}\n",
                ]);
                return;
            }

            $opts = "-p {$port} -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null";
            $ssh = $sshType === 'key'
                ? "ssh -i {$keyPath} {$opts} {$user}@{$ip} 'bash -se < /dev/stdin && echo EXIT_CODE:\$?'"
                : "sshpass -p '{$password}' ssh {$opts} {$user}@{$ip} 'bash -se < /dev/stdin && echo EXIT_CODE:\$?'";

            $scriptPath = base_path('resources/scripts/deploy-openvpn.sh');
            if (!is_file($scriptPath)) {
                $this->vpnServer->update([
                    'is_deploying' => false,
                    'deployment_status' => 'failed',
                    'deployment_log' => "❌ Missing script: {$scriptPath}\n",
                ]);
                return;
            }
            $script = file_get_contents($scriptPath);

            $proc = proc_open($ssh, [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']], $pipes);
            if (!is_resource($proc)) {
                $this->vpnServer->update([
                    'is_deploying' => false,
                    'deployment_status' => 'failed',
                    'deployment_log' => "❌ Could not open SSH process\n",
                ]);
                return;
            }

            fwrite($pipes[0], $script);
            fclose($pipes[0]);

            $output = '';
            $error = '';
            $streams = [$pipes[1], $pipes[2]];
            $streamMap = [
                (int)$pipes[1] => 1,
                (int)$pipes[2] => 2,
            ];

            while (count($streams)) {
                $read = $streams;
                $write = $except = [];
                if (stream_select($read, $write, $except, 5) === false) break;
                foreach ($read as $r) {
                    $line = fgets($r);
                    if ($line === false) {
                        $key = array_search($r, $streams, true);
                        if ($key !== false) {
                            fclose($streams[$key]);
                            unset($streams[$key]);
                        }
                        continue;
                    }
                    $i = $streamMap[(int)$r];
                    $this->vpnServer->appendLog(rtrim($line, "\r\n"));
                    if ($i === 1) $output .= $line;
                    else $error .= $line;
                }
            }

            proc_close($proc);
            $log = $this->vpnServer->deployment_log . $output . $error;

            $exit = preg_match('/EXIT_CODE:(\d+)/', $log, $matches) ? (int)$matches[1] : 255;
            $log = preg_replace('/EXIT_CODE:\d+\s*/', '', $log);

            $lines = explode("\n", $log);
            $filtered = array_filter($lines, fn($line) =>
                !preg_match('/^\.+\+|\*+|DH parameters appear to be ok|Generating DH parameters/', $line) &&
                !preg_match('/DEPRECATED OPTION/', $line) &&
                trim($line) !== ''
            );
            $log = implode("\n", $filtered);

            $statusText = $exit === 0 ? 'succeeded' : 'failed';
            $log .= $exit === 0
                ? "\n✅ Deployment succeeded"
                : "\n❌ Deployment failed with exit code {$exit}";

            $this->vpnServer->update([
                'is_deploying' => false,
                'deployment_status' => strtolower($statusText),
                'deployment_log' => $log,
                'status' => $exit === 0 ? 'online' : 'offline',
            ]);
        } catch (\Throwable $e) {
            Log::error('DEPLOY_JOB: Exception: ' . $e->getMessage());
            $this->vpnServer->update([
                'is_deploying' => false,
                'deployment_status' => 'failed',
                'deployment_log' => "❌ Job exception: {$e->getMessage()}\n",
                'status' => 'offline',
            ]);
            throw $e;
        }
    }

    public function failed(\Throwable $e): void
    {
        $this->vpnServer->update([
            'is_deploying' => false,
            'deployment_status' => 'failed',
            'deployment_log' => "❌ Job exception: {$e->getMessage()}\n",
            'status' => 'offline',
        ]);
    }
}
