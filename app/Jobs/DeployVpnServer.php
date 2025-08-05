<?php

namespace App\Jobs;

use App\Models\VpnServer;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Queue\SerializesModels;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use App\Jobs\SyncOpenVPNCredentials;
use Throwable;

class DeployVpnServer implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public VpnServer $vpnServer;

    public function __construct(VpnServer $vpnServer)
    {
        $this->vpnServer = $vpnServer;
    }

    public function handle(): void
    {
        Log::info("🚀 Starting DeployVpnServer job for server #{$this->vpnServer->id}");

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
            $ip      = $this->vpnServer->ip_address;
            $port    = $this->vpnServer->ssh_port ?? 22;
            $user    = $this->vpnServer->ssh_user;
            $sshType = $this->vpnServer->ssh_type;

            $sshOpts = "-p $port -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null";

            $authPart = match ($sshType) {
                'key' => "-i " . escapeshellarg(storage_path('app/ssh_keys/' . ($this->vpnServer->ssh_key ?? 'id_rsa'))),
                'password' => "sshpass -p '{$this->vpnServer->ssh_password}'",
                default => '',
            };

            $sshCmdBase = "$authPart ssh $sshOpts $user@$ip";

            // Test SSH connection
            exec("$sshCmdBase 'echo CONNECTION_OK'", $testOutput, $testStatus);
            if ($testStatus !== 0) {
                $this->failWith("❌ SSH connection failed to $ip");
                return;
            }

            Log::info("✅ SSH test successful for server $ip");

            // Get or generate VPN credentials
            $vpnUser = 'admin';
            $vpnPass = substr(md5(uniqid()), 0, 12);

            $existing = $this->vpnServer->vpnUsers()->where('is_active', true)->first();
            if ($existing) {
                $vpnUser = $existing->username;
                $vpnPass = $existing->plain_password ?? $vpnPass;
                Log::info("🔑 Reusing existing user: $vpnUser");
            } else {
                Log::info("🔑 Using default user: $vpnUser with generated password");
            }

            // Build SSH execution command
            $env = "VPN_USER='$vpnUser' VPN_PASS='$vpnPass'";
            $fullCmd = "$env $sshCmdBase 'bash -se < /dev/stdin && echo EXIT_CODE:\$?'";

            $scriptPath = base_path('resources/scripts/deploy-openvpn.sh');
            if (!is_file($scriptPath)) {
                $this->failWith("❌ Missing script at $scriptPath");
                return;
            }

            $script = file_get_contents($scriptPath);

            // Run script
            $pipes = [];
            $proc = proc_open($fullCmd, [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ], $pipes);

            if (!is_resource($proc)) {
                $this->failWith("❌ Failed to open SSH process");
                return;
            }

            fwrite($pipes[0], $script);
            fclose($pipes[0]);

            $output = '';
            $error = '';
            $map = [(int)$pipes[1] => 'out', (int)$pipes[2] => 'err'];
            $streams = [$pipes[1], $pipes[2]];

            while ($streams) {
                $read = $streams;
                $write = null;
                $except = null;
                if (stream_select($read, $write, $except, 5) === false) break;

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
            $exitCode = preg_match('/EXIT_CODE:(\d+)/', $combined, $m) ? (int)$m[1] : 255;
            $cleanLog = preg_replace('/EXIT_CODE:\d+/', '', $combined);

            $filteredLines = array_filter(explode("\n", $cleanLog), fn($line) =>
                trim($line) !== '' && !preg_match('/^\.+\+|\*+|DH parameters appear to be ok|Generating DH parameters|DEPRECATED OPTION/', $line)
            );

            $finalLog = implode("\n", $filteredLines);
            $status = $exitCode === 0 ? 'succeeded' : 'failed';

            if ($exitCode === 0) {
                $finalLog .= "\n✅ Deployment succeeded";
                SyncOpenVPNCredentials::dispatch($this->vpnServer);
            } else {
                $finalLog .= "\n❌ Deployment failed (exit code: $exitCode)";
            }

            Log::info("🔍 Deployment exit code: $exitCode");

            $this->vpnServer->update([
                'is_deploying' => false,
                'deployment_status' => $status,
                'deployment_log' => $finalLog,
                'status' => $exitCode === 0 ? 'online' : 'offline',
            ]);
        } catch (Throwable $e) {
            $this->failWith("❌ Exception during deployment: " . $e->getMessage(), $e);
        }

        Log::info("✅ DeployVpnServer job complete for server #{$this->vpnServer->id}");
    }

    public function failed(Throwable $e): void
    {
        $this->failWith("❌ Job failed with exception: " . $e->getMessage(), $e);
    }

    private function failWith(string $message, Throwable $e = null): void
    {
        Log::error($message);
        if ($e) Log::error($e);

        $this->vpnServer->update([
            'is_deploying' => false,
            'deployment_status' => 'failed',
            'deployment_log' => $message,
            'status' => 'offline',
        ]);
    }
}