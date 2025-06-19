<?php

namespace App\Jobs;

use App\Models\VpnServer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
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

        /* ───── Connection details ───── */
        $ip       = $this->server->ip_address;
        $port     = $this->server->ssh_port;
        $user     = $this->server->ssh_user;
        $sshType  = $this->server->ssh_type;
        $password = $this->server->ssh_password;
        $keyPath  = storage_path('app/ssh_keys/id_rsa');

        if ($sshType === 'key' && ! is_file($keyPath)) {
            $this->server->update([
                'deployment_status' => 'error',
                'deployment_log'    => "❌ Missing SSH key: {$keyPath}\n",
            ]);
            return;
        }

        $opts  = "-p {$port} -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null";
        $ssh   = $sshType === 'key'
            ? "ssh -i {$keyPath} {$opts} {$user}@{$ip} 'bash -s'"
            : "sshpass -p '{$password}' ssh {$opts} {$user}@{$ip} 'bash -s'";

        /* ───── Initial DB state ───── */
        $this->server->update([
            'deployment_status' => 'running',
            'deployment_log'    => "Starting deployment on {$ip} …\n",
        ]);

        /* ───── Bash script (idempotent) ───── */
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
cat <<'SCRIPT' > /etc/openvpn/auth/checkpsw.sh
#!/bin/sh
PASSFILE="/etc/openvpn/auth/psw-file"
CORRECT=$(grep "^$1 " "$PASSFILE" | cut -d' ' -f2-)
[ "$2" = "$CORRECT" ] && exit 0 || exit 1
SCRIPT
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
echo "✅ Deployment complete. OpenVPN is ready."
BASH;

        /* ───── Launch SSH process ───── */
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

        /* Send script */
        fwrite($pipes[0], $script);
        fclose($pipes[0]);

        /* Non-blocking streams */
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $log = $this->server->deployment_log;

        /* ───── Live loop ───── */
        while (true) {
            $out = fgets($pipes[1]) ?: '';
            $err = fgets($pipes[2]) ?: '';

            if ($out !== '' || $err !== '') {
                $log .= $out.$err;
                // atomic append
                $this->server->update(['deployment_log' => $log]);
            }

            $status = proc_get_status($proc);
            if (! $status['running']) {
                break;
            }
            usleep(200_000); // 0.2 s
        }

        fclose($pipes[1]); fclose($pipes[2]);
        $exit = proc_close($proc);

        $this->server->update([
            'deployment_status' => $exit === 0 ? 'deployed' : 'failed',
            'deployment_log'    => $log . ($exit ? "\n❌ Exit code {$exit}\n" : ''),
        ]);

        Log::info("DeployVpnServer finished for {$ip} (exit {$exit})");
    }

    public function failed(\Throwable $e): void
    {
        $this->server->update([
            'deployment_status' => 'failed',
            'deployment_log'    => "❌ Job exception: {$e->getMessage()}\n",
        ]);
    }
}
