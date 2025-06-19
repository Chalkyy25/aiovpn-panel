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

    public VpnServer $server;

    public function __construct(VpnServer $server)
    {
        $this->server = $server;
    }

    public function handle(): void
    {
\Log::info('🔥 handle() started for ' . ($this->server->id ?? 'null'));
    // ...rest of code
        /* ───── Connection details ───────────────────────── */
        $ip       = $this->server->ip_address;
        $port     = $this->server->ssh_port;
        $user     = $this->server->ssh_user;
        $sshType  = $this->server->ssh_type;          // key | password
        $password = $this->server->ssh_password;      // only if password auth

        /* ───── Path to the shared key ───────────────────── */
        $keyPath  = storage_path('app/ssh_keys/id_rsa');

        /* ───── Safety check ─────────────────────────────── */
        if ($sshType === 'key' && ! file_exists($keyPath)) {
            $this->server->update([
                'deployment_status' => 'error',
                'deployment_log'    => "❌ Global SSH key missing at {$keyPath}"
            ]);
            return;
        }

        /* ───── Build SSH command ────────────────────────── */
        $sshOpts  = "-p {$port} -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null";
        $sshCmd   = $sshType === 'key'
            ? "ssh -i {$keyPath} {$sshOpts} {$user}@{$ip} 'bash -s'"
            : "sshpass -p '{$password}' ssh {$sshOpts} {$user}@{$ip} 'bash -s'";

        /* ───── Record start ─────────────────────────────── */
        $this->server->update([
            'deployment_status' => 'running',
            'deployment_log'    => "Starting deployment on {$ip} …\n",
        ]);

        /* ───── Provision script ─────────────────────────── */
$script = <<<'BASH'
echo "[1/5] Updating packages…"
apt-get update -y

echo "[2/5] Installing OpenVPN & Easy-RSA…"
DEBIAN_FRONTEND=noninteractive apt-get install -y openvpn easy-rsa

echo "[3/5] Creating auth directory & psw-file…"
mkdir -p /etc/openvpn/auth
echo "testuser testpass" > /etc/openvpn/auth/psw-file
chmod 400 /etc/openvpn/auth/psw-file

echo "[4/5] (Put additional server.conf tweaks here)"

echo "[5/5] Enabling and starting OpenVPN service…"
systemctl enable openvpn@server
systemctl start  openvpn@server

echo "✅ Deployment complete."
BASH;

        /* ───── Execute ─────────────────────────────────── */
        $proc = proc_open($sshCmd,
            [ 0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w'] ],
            $pipes
        );

if (! is_resource($proc)) {
    $existingLog = $this->server->deployment_log ?? '';
    $this->server->update([
        'deployment_status' => 'failed',
        'deployment_log'    => $existingLog . "❌ Could not start SSH process.\n",
    ]);
    return;
}
        fwrite($pipes[0], $script);
        fclose($pipes[0]);

        $output = stream_get_contents($pipes[1]);
        $error  = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exit   = proc_close($proc);

        /* ───── Persist logs & status ────────────────────── */
$this->server->update([
    'deployment_status' => $exit === 0 ? 'deployed' : 'failed',
    'deployment_log'    => ($this->server->deployment_log ?? '') . $output . ($error ? "\n[ERROR]\n{$error}" : ''),
]);
        Log::info("DeployVpnServer finished for {$ip} with exit code {$exit}");
    }

    public function failed(\Throwable $e): void
    {
        $this->server->update([
            'deployment_status' => 'failed',
            'deployment_log'    => "❌ Job exception: " . $e->getMessage(),
        ]);
    }
}
