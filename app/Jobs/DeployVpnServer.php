<?php

namespace App\Jobs;

use App\Models\VpnServer;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Queue\SerializesModels;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use App\Jobs\SyncOpenVPNCredentials;

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
        Log::info("🚀 Starting DeployVpnServer job");

        if ($this->vpnServer->is_deploying) {
            Log::warning("⚠️ Already deploying: Server #{$this->vpnServer->id}");
            return;
        }

        $this->vpnServer->update([
            'is_deploying' => true,
            'deployment_status' => 'running',
            'deployment_log' => "🚀 Starting deployment on {$this->vpnServer->ip_address}…\n",
        ]);

        try {
            $ip       = $this->vpnServer->ip_address;
            $port     = $this->vpnServer->ssh_port ?? 22;
            $user     = $this->vpnServer->ssh_user;
            $password = $this->vpnServer->ssh_password;
            $keyPath  = '/var/www/aiovpn/storage/app/ssh_keys/id_rsa';

            if (!is_file($keyPath)) {
                $this->failWith("❌ SSH key not found at $keyPath");
                return;
            }

            $sshOpts = "-p $port -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null";
            $sshCmd = "ssh -i $keyPath $sshOpts $user@$ip 'bash -se < /dev/stdin && echo EXIT_CODE:\$?'";

            $scriptPath = base_path('resources/scripts/deploy-openvpn.sh');
            if (!is_file($scriptPath)) {
                $this->failWith("❌ Missing deployment script at $scriptPath");
                return;
            }

            $script = file_get_contents($scriptPath);

            $pipes = [];
            $proc = proc_open($sshCmd, [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']], $pipes);
            if (!is_resource($proc)) {
                $this->failWith("❌ Failed to open SSH process");
                return;
            }

            fwrite($pipes[0], $script);
            fclose($pipes[0]);

            $output = '';
            $error = '';
            $streams = [$pipes[1], $pipes[2]];
            $map = [(int)$pipes[1] => 'out', (int)$pipes[2] => 'err'];

            while ($streams) {
                $read = $streams;
                $w = null;
                $e = null;
                if (stream_select($read, $w, $e, 5) === false) break;

                foreach ($read as $r) {
                    $line = fgets($r);
                    if ($line === false) {
                        fclose($r);
                        unset($streams[array_search($r, $streams, true)]);
                        continue;
                    }

                    $clean = rtrim($line, "\r\n");
                    $this->vpnServer->appendLog($clean);

                    if ($map[(int)$r] === 'out') $output .= $line;
                    else $error .= $line;
                }
            }

            proc_close($proc);

            $combined = $this->vpnServer->deployment_log . $output . $error;
            $exit = preg_match('/EXIT_CODE:(\d+)/', $combined, $m) ? (int)$m[1] : 255;
            $combined = preg_replace('/EXIT_CODE:\d+/', '', $combined);

            $lines = explode("\n", $combined);
            $filtered = array_filter($lines, fn($l) =>
                !preg_match('/^\.+\+|\*+|DH parameters appear to be ok|Generating DH parameters|DEPRECATED OPTION/', $l)
                && trim($l) !== ''
            );

            $finalLog = implode("\n", $filtered);
            $status = $exit === 0 ? 'succeeded' : 'failed';

            $finalLog .= $exit === 0
                ? "\n✅ Deployment succeeded"
                : "\n❌ Deployment failed (exit code: $exit)";
            Log::info("🔍 Exit code after VPN deploy: $exit");

            // --- Upload id_rsa public key for live polling automation ---
            if ($exit === 0) {
                $webKeyPub = '/var/www/aiovpn/storage/app/ssh_keys/id_rsa.pub';
                $remoteTmp = '/tmp/id_rsa.pub';

                // Copy public key to server
                $scpCmd = "scp -i {$keyPath} -P {$port} -o StrictHostKeyChecking=no {$webKeyPub} {$user}@{$ip}:{$remoteTmp}";
                exec($scpCmd, $scpOut, $scpCode);
                if ($scpCode !== 0) {
                    Log::error("❌ Failed to copy polling public key: " . implode("\n", $scpOut));
                } else {
                    Log::info("✅ Copied polling public key to {$remoteTmp}");

                    // Append it to authorized_keys
                    $sshAddKeyCmd = "ssh -i {$keyPath} -p {$port} -o StrictHostKeyChecking=no {$user}@{$ip} 'cat {$remoteTmp} >> ~/.ssh/authorized_keys && chmod 600 ~/.ssh/authorized_keys && rm {$remoteTmp}'";
                    exec($sshAddKeyCmd, $addOut, $addCode);
                    if ($addCode !== 0) {
                        Log::error("❌ Failed to add polling public key to authorized_keys: " . implode("\n", $addOut));
                    } else {
                        Log::info("✅ Added polling public key to authorized_keys successfully");
                    }
                }

                // Dispatch SyncOpenVPNCredentials job
                SyncOpenVPNCredentials::dispatch($this->vpnServer);
            }

            $this->vpnServer->update([
                'is_deploying' => false,
                'deployment_status' => $status,
                'deployment_log' => $finalLog,
                'status' => $exit === 0 ? 'online' : 'offline',
            ]);
        } catch (\Throwable $e) {
            $this->failWith("❌ Exception: " . $e->getMessage(), $e);
        }

        Log::info("✅ DeployVpnServer job finished");
    }

    public function failed(\Throwable $e): void
    {
        $this->failWith("❌ Job exception: " . $e->getMessage(), $e);
    }

    private function failWith(string $message, \Throwable $e = null): void
    {
        Log::error('DEPLOY_JOB: ' . $message);
        if ($e) Log::error($e);

        $this->vpnServer->update([
            'is_deploying' => false,
            'deployment_status' => 'failed',
            'deployment_log' => $message,
            'status' => 'offline',
        ]);
    }
}