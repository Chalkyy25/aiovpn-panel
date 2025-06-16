<?php

namespace App\Jobs;

use App\Models\VpnServer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DeployVpnServer implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public $server;

    public function __construct(VpnServer $server)
    {
        $this->server = $server;
    }

    public function handle(): void
    {
        $server = $this->server;

        $ip = $server->ip_address;
        $sshPort = $server->ssh_port;
        $username = $server->ssh_user;
        $sshType = $server->ssh_type;
        $sshKey = $server->ssh_key;
        $password = $server->ssh_password;

        // Default key path
        $keyPath = storage_path('ssh/id_rsa');

        // If using a custom key, write it to a temp file
        if ($sshType === 'key' && $sshKey) {
            $keyPath = storage_path('ssh/temp_key_' . $server->id);
            file_put_contents($keyPath, $sshKey);
            chmod($keyPath, 0600);
        }

        // Check if the key file exists
        if ($sshType === 'key' && !file_exists($keyPath)) {
            $server->update([
                'deployment_status' => 'error',
                'deployment_log' => "❌ SSH key file not found at $keyPath"
            ]);
            return;
        }

        // Build SSH command
        if ($sshType === 'key') {
            $sshCmd = "ssh -p $sshPort -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -i $keyPath $username@$ip 'bash -s'";
        } else {
            $sshCmd = "sshpass -p '$password' ssh -p $sshPort -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null $username@$ip 'bash -s'";
        }

        $server->update([
            'deployment_status' => 'running',
            'deployment_log' => 'Starting deployment...'
        ]);
        if (method_exists($server, 'appendLog')) {
            $server->appendLog("Connecting to $username@$ip:$sshPort ...");
        }

        $script = <<<'BASH'
echo "[1/5] Updating packages..."
apt update
echo "[2/5] Installing OpenVPN, Easy-RSA, sshpass..."
apt install -y openvpn easy-rsa sshpass
echo "[3/5] Setting up authentication file..."
mkdir -p /etc/openvpn/auth
echo "testuser testpass" > /etc/openvpn/auth/psw-file
chmod 400 /etc/openvpn/auth/psw-file
echo "[4/5] (Your config steps here)"
echo "[5/5] Done!"
BASH;

        $proc = proc_open($sshCmd, [
            0 => ['pipe', 'r'], // stdin
            1 => ['pipe', 'w'], // stdout
            2 => ['pipe', 'w'], // stderr
        ], $pipes);

        if (is_resource($proc)) {
            fwrite($pipes[0], $script);
            fclose($pipes[0]);
            $output = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $returnCode = proc_close($proc);

            // Append output to logs
            if (method_exists($server, 'appendLog')) {
                $server->appendLog($output);
                if ($error) {
                    $server->appendLog("[ERROR]\n" . $error);
                }
            } else {
                $server->update([
                    'deployment_log' => $server->deployment_log . "\n" . $output . ($error ? "\n[ERROR]\n$error" : ''),
                ]);
            }

            $server->update([
                'deployment_status' => $returnCode === 0 ? 'deployed' : 'failed',
            ]);
        } else {
            $server->update([
                'deployment_status' => 'failed',
                'deployment_log' => 'Deployment process failed to start.'
            ]);
        }

        // Optional: Clean up temp key file
        if ($sshType === 'key' && $sshKey && file_exists($keyPath) && str_contains($keyPath, 'temp_key_')) {
            @unlink($keyPath);
        }
    }

    public function failed(\Exception $exception): void
    {
        $this->server->update([
            'deployment_status' => 'failed',
            'deployment_log' => "❌ Deployment failed: " . $exception->getMessage()
        ]);
    }
}
