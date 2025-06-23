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
            Log::info('🔥 DeployVpnServer started for #' . $this->vpnServer->id);

            $ip       = $this->vpnServer->ip_address;
            $port     = $this->vpnServer->ssh_port ?? 22;
            $user     = $this->vpnServer->ssh_user;
            $sshType  = $this->vpnServer->ssh_type;
            $password = $this->vpnServer->ssh_password;
            $keyPath  = $this->vpnServer->ssh_key ?? '/var/www/aiovpn/storage/app/ssh_keys/id_rsa';

            if ($sshType === 'key' && !is_file($keyPath)) {
                Log::error("DEPLOY_JOB: SSH key missing at $keyPath");
                $this->vpnServer->update([
                    'deployment_status' => 'failed',
                    'deployment_log'    => "❌ Missing SSH key: {$keyPath}\n",
                ]);
                return;
            }

            $opts = "-p {$port} -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null";
            $ssh = $sshType === 'key'
                ? "ssh -i {$keyPath} {$opts} {$user}@{$ip} 'stdbuf -oL -eL bash -s && echo EXIT_CODE:\$?'"
                : "sshpass -p '{$password}' ssh {$opts} {$user}@{$ip} 'stdbuf -oL -eL bash -s && echo EXIT_CODE:\$?'";

            $this->vpnServer->update([
                'deployment_status' => 'running',
                'deployment_log'    => "Starting deployment on {$ip} …\n",
            ]);

            // Read the BASH script from your file (make sure it's in your repo!)
            $scriptPath = base_path('resources/scripts/deploy-openvpn.sh');
            if (!is_file($scriptPath)) {
                $this->vpnServer->update([
                    'deployment_status' => 'failed',
                    'deployment_log'    => "❌ Missing script: {$scriptPath}\n",
                ]);
                return;
            }
            $script = file_get_contents($scriptPath);

            Log::info("DEPLOY_JOB: Before proc_open");
            $proc = proc_open($ssh, [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']], $pipes);
            Log::info("DEPLOY_JOB: After proc_open");

            if (!is_resource($proc)) {
                Log::error("DEPLOY_JOB: proc_open failed");
                $this->vpnServer->update([
                    'deployment_status' => 'failed',
                    'deployment_log'    => "❌ Could not open SSH process\n",
                ]);
                return;
            }

            fwrite($pipes[0], $script);
            fclose($pipes[0]);

            Log::info("DEPLOY_JOB: Script sent to remote");
            Log::info("DEPLOY_JOB: Script length sent: " . strlen($script));

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
                if (stream_select($read, $write, $except, 5) === false) {
                    break;
                }
                foreach ($read as $r) {
                    $line = fgets($r);
                    if ($line === false) {
                        // Remove closed streams
                        $key = array_search($r, $streams, true);
                        if ($key !== false) {
                            fclose($streams[$key]);
                            unset($streams[$key]);
                        }
                        continue;
                    }
                    $i = $streamMap[(int)$r];
                    $this->vpnServer->appendLog(rtrim($line, "\r\n"));
                    if ($i === 1) {
                        $output .= $line;
                    } else {
                        $error .= $line;
                    }
                }
            }

            proc_close($proc);

            Log::info("DEPLOY_JOB: handle() completed for server #" . $this->vpnServer->id);

            $log = $this->vpnServer->deployment_log . $output . $error;

            $exit = null;
            if (preg_match('/EXIT_CODE:(\d+)/', $output . $error, $matches)) {
                $exit = (int)$matches[1];
                $log = preg_replace('/EXIT_CODE:\d+\s*/', '', $log);
            } else {
                $exit = 255;
                $log .= "\n❌ Could not determine remote exit code\n";
            }

            $lines = explode("\n", $log);
            $filtered = array_filter($lines, function ($line) {
                return !preg_match('/^\.+\+|\*+|DH parameters appear to be ok|Generating DH parameters/', $line)
                    && !preg_match('/DEPRECATED OPTION/', $line)
                    && trim($line) !== '';
            });
            $log = implode("\n", $filtered);

            $statusText = $exit === 0 ? 'succeeded' : 'failed';

            if ($exit === 0) {
                Log::info("Deployment succeeded for {$ip}");
                $log .= "\n✅ Deployment succeeded";
            } else {
                Log::error("Deployment failed for {$ip} with exit code {$exit}");
                $log .= "\n❌ Deployment failed with exit code {$exit}";
            }

            $this->vpnServer->update([
                'deployment_status' => strtolower($statusText),
                'deployment_log'    => $log,
                'status'            => $exit === 0 ? 'online' : 'offline',
            ]);
        } catch (\Throwable $e) {
            Log::error('DEPLOY_JOB: Exception: ' . $e->getMessage());
            $this->vpnServer->update([
                'deployment_status' => 'failed',
                'deployment_log'    => "❌ Job exception: {$e->getMessage()}\n",
                'status'            => 'offline',
            ]);
            throw $e; // Let Laravel mark the job as failed
        }
    }

    public function failed(\Throwable $e): void
    {
        $this->vpnServer->update([
            'deployment_status' => 'failed',
            'deployment_log'    => "❌ Job exception: {$e->getMessage()}\n",
            'status'            => 'offline',
        ]);
    }
}
