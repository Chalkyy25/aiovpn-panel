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
        Log::info('🔥 DeployVpnServer started for #' . $this->server->id);

        $ip       = $this->server->ip_address;
        $port     = $this->server->ssh_port;
        $user     = $this->server->ssh_user;
        $sshType  = $this->server->ssh_type;
        $password = $this->server->ssh_password;
        $keyPath  = storage_path('app/ssh_keys/id_rsa');

        if ($sshType === 'key' && ! is_file($keyPath)) {
            $this->server->update([
                'deployment_status' => 'failed',
                'deployment_log'    => "❌ Missing SSH key: {$keyPath}\n",
            ]);
            return;
        }

        $opts  = "-p {$port} -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null";
        $ssh   = $sshType === 'key'
            ? "ssh -i {$keyPath} {$opts} {$user}@{$ip} 'bash -s; echo EXIT_CODE:\$?'"
            : "sshpass -p '{$password}' ssh {$opts} {$user}@{$ip} 'bash -s; echo EXIT_CODE:\$?'";

        $this->server->update([
            'deployment_status' => 'running',
            'deployment_log'    => "Starting deployment on {$ip} …\n",
        ]);

        $script = <<<'BASH'
set -e
trap 'CODE=$?; echo "❌ Deployment failed with code: $CODE"; echo "EXIT_CODE:$CODE"; exit $CODE' ERR

export EASYRSA_BATCH=1
export EASYRSA_REQ_CN="OpenVPN-CA"

echo "[1/8] Clearing old OpenVPN setup (if any)…"
systemctl stop openvpn@server || true
rm -rf /etc/openvpn/*
mkdir -p /etc/openvpn/auth
: > /etc/openvpn/ipp.txt

echo "[2/8] Updating packages…"
apt-get update -y

echo "[3/8] Installing OpenVPN & Easy-RSA…"
DEBIAN_FRONTEND=noninteractive apt-get install -y openvpn easy-rsa

echo "[4/8] Setting up Easy-RSA PKI & generating certificates…"
EASYRSA_DIR=/etc/openvpn/easy-rsa
cp -a /usr/share/easy-rsa "$EASYRSA_DIR" 2>/dev/null || true
cd "$EASYRSA_DIR"
./easyrsa init-pki
./easyrsa build-ca nopass
./easyrsa gen-dh
openvpn --genkey --secret ta.key
./easyrsa gen-req server nopass
./easyrsa sign-req server server

echo "[5/8] Copying certs and keys to /etc/openvpn…"
cp -f pki/ca.crt pki/issued/server.crt pki/private/server.key pki/dh.pem ta.key /etc/openvpn/

echo "[6/8] Creating user/pass auth files…"
echo "testuser testpass" > /etc/openvpn/auth/psw-file
chmod 600 /etc/openvpn/auth/psw-file
cat <<'SH' > /etc/openvpn/auth/checkpsw.sh
#!/bin/sh
PASSFILE="/etc/openvpn/auth/psw-file"
CORRECT=$(grep "^$1 " "$PASSFILE" | cut -d' ' -f2-)
[ "$2" = "$CORRECT" ] && exit 0 || exit 1
SH
chmod 700 /etc/openvpn/auth/checkpsw.sh

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

EXIT_CODE=$?
echo "✅ Deployment finished with code: $EXIT_CODE"
echo "EXIT_CODE:$EXIT_CODE"
exit $EXIT_CODE

BASH;

        $proc = proc_open(
            $ssh,
            [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']],
            $pipes
        );
        if (! is_resource($proc)) {
            $this->server->update([
                'deployment_status' => 'failed',
                'deployment_log'    => "❌ Could not open SSH process\n",
            ]);
            return;
        }

        fwrite($pipes[0], $script);
        fclose($pipes[0]);

        // Remove live log streaming, just collect all output at once
        $output = stream_get_contents($pipes[1]);
        $error  = stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);

        $log = $this->server->deployment_log . $output . $error;

        // Parse the exit code from the output
        $exit = null;
        if (preg_match('/EXIT_CODE:(\d+)/', $output . $error, $matches)) {
            $exit = (int)$matches[1];
            $log = preg_replace('/EXIT_CODE:\d+\s*/', '', $log);
        } else {
            $exit = 255;
            $log .= "\n❌ Could not determine remote exit code\n";
        }

        // After collecting $log or $outputBuffer
        $lines = explode("\n", $log);
        $filtered = array_filter($lines, function ($line) {
            // Filter out DH parameter lines, warnings, or other noisy output
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

        $this->server->update([
            'deployment_status' => $statusText,
            'deployment_log'    => $log,
        ]);
    }

    public function failed(\Throwable $e): void
    {
        $this->server->update([
            'deployment_status' => 'failed',
            'deployment_log'    => "❌ Job exception: {$e->getMessage()}\n",
        ]);
    }
}
