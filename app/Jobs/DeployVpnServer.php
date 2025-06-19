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
        Log::info('🔥 handle() started for ' . ($this->server->id ?? 'null'));

        // Connection details
        $ip       = $this->server->ip_address;
        $port     = $this->server->ssh_port;
        $user     = $this->server->ssh_user;
        $sshType  = $this->server->ssh_type;
        $password = $this->server->ssh_password;

        // Path to shared key
        $keyPath  = storage_path('app/ssh_keys/id_rsa');

        // Check for SSH key file if needed
        if ($sshType === 'key' && !file_exists($keyPath)) {
            $this->server->update([
                'deployment_status' => 'error',
                'deployment_log'    => "❌ Global SSH key missing at {$keyPath}\n"
            ]);
            return;
        }

        // Build SSH command
        $sshOpts = "-p {$port} -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null";
        $sshCmd  = $sshType === 'key'
            ? "ssh -i {$keyPath} {$sshOpts} {$user}@{$ip} 'bash -s'"
            : "sshpass -p '{$password}' ssh {$sshOpts} {$user}@{$ip} 'bash -s'";

        // Set status
        $this->server->update([
            'deployment_status' => 'running',
            'deployment_log'    => "Starting deployment on {$ip} …\n",
        ]);

        // Provisioning script
$script = <<<'BASH'
set -e

echo "[1/8] Clearing old OpenVPN setup (if any)…"
systemctl stop openvpn@server || true
rm -rf /etc/openvpn/*
mkdir -p /etc/openvpn/auth
echo > /etc/openvpn/ipp.txt

echo "[2/8] Updating packages…"
apt-get update -y

echo "[3/8] Installing OpenVPN & Easy-RSA…"
DEBIAN_FRONTEND=noninteractive apt-get install -y openvpn easy-rsa

echo "[4/8] Setting up Easy-RSA PKI & generating certificates…"
EASYRSA_DIR=/etc/openvpn/easy-rsa
cp -r /usr/share/easy-rsa "$EASYRSA_DIR" 2>/dev/null || true
cd "$EASYRSA_DIR"
./easyrsa init-pki
echo | ./easyrsa build-ca nopass
./easyrsa gen-dh
openvpn --genkey --secret ta.key
./easyrsa gen-req server nopass
echo yes | ./easyrsa sign-req server server

echo "[5/8] Copying certs and keys to /etc/openvpn…"
cp -f pki/ca.crt pki/issued/server.crt pki/private/server.key pki/dh.pem ta.key /etc/openvpn/

echo "[6/8] Creating user/pass auth files…"
echo "testuser testpass" > /etc/openvpn/auth/psw-file
chmod 600 /etc/openvpn/auth/psw-file
chown root:root /etc/openvpn/auth/psw-file

cat <<'SCRIPT' > /etc/openvpn/auth/checkpsw.sh
#!/bin/sh
PASSFILE="/etc/openvpn/auth/psw-file"
CORRECT_PASSWORD=$(grep "^$1 " "$PASSFILE" | cut -d' ' -f2-)
if [ "$2" = "$CORRECT_PASSWORD" ]; then
    exit 0
else
    exit 1
fi
SCRIPT
chmod 700 /etc/openvpn/auth/checkpsw.sh
chown root:root /etc/openvpn/auth/checkpsw.sh

echo "[7/8] Writing server.conf…"
cat <<'CONF' > /etc/openvpn/server.conf
port 1194
proto udp
dev tun
ca ca.crt
cert server.crt
key server.key
dh dh.pem
auth SHA256
tls-auth ta.key 0
topology subnet
server 10.8.0.0 255.255.255.0
ifconfig-pool-persist /etc/openvpn/ipp.txt
keepalive 10 120
cipher AES-256-CBC
user nobody
group nogroup
persist-key
persist-tun
status /etc/openvpn/openvpn-status.log
verb 3
auth-user-pass-verify /etc/openvpn/auth/checkpsw.sh via-env
script-security 3
CONF

echo "[8/8] Enabling and starting OpenVPN service…"
systemctl enable openvpn@server
systemctl restart openvpn@server

echo "✅ Deployment complete. OpenVPN is ready."
BASH;
        // SSH execution
        $proc = proc_open($sshCmd,
            [ 0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'] ],
            $pipes
        );

        if (!is_resource($proc)) {
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

        // Save deployment log and status
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
