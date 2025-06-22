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
            $port     = $this->vpnServer->ssh_port;
            $user     = $this->vpnServer->ssh_user;
            $sshType  = $this->vpnServer->ssh_type;
            $password = $this->vpnServer->ssh_password;
            $keyPath  = $this->vpnServer->ssh_key ?? '/var/www/aiovpn/storage/app/ssh_keys/id_rsa';

            Log::info("DEPLOY_JOB: Key path is $keyPath");

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
                ? "ssh -i {$keyPath} {$opts} {$user}@{$ip} 'stdbuf -oL -eL bash -s; echo EXIT_CODE:\$?'"
                : "sshpass -p '{$password}' ssh {$opts} {$user}@{$ip} 'stdbuf -oL -eL bash -s; echo EXIT_CODE:\$?'";

            Log::info("DEPLOY_JOB: SSH command: $ssh");

            $this->vpnServer->update([
                'deployment_status' => 'running',
                'deployment_log'    => "Starting deployment on {$ip} …\n",
            ]);

            $script = <<<'BASH'
#!/bin/bash

set -e
trap 'CODE=$?; echo "❌ Deployment failed with code: $CODE"; echo "EXIT_CODE:$CODE"; exit $CODE' ERR

export DEBIAN_FRONTEND=noninteractive
export EASYRSA_BATCH=1
export EASYRSA_REQ_CN="OpenVPN-CA"

echo "[1/9] Updating package lists and upgrading system…"
apt-get update -y
apt-get upgrade -y

echo "[2/9] Installing OpenVPN, Easy-RSA, and vnStat…"
DEBIAN_FRONTEND=noninteractive apt-get install -y openvpn easy-rsa vnstat curl wget lsb-release ca-certificates

echo "[3/9] Stopping any running OpenVPN service and cleaning up…"
systemctl stop openvpn@server || true
rm -rf /etc/openvpn/*
mkdir -p /etc/openvpn/auth
: > /etc/openvpn/ipp.txt

echo "[4/9] Setting up Easy-RSA PKI & generating certificates…"
EASYRSA_DIR=/etc/openvpn/easy-rsa
cp -a /usr/share/easy-rsa "$EASYRSA_DIR" 2>/dev/null || true
cd "$EASYRSA_DIR"
./easyrsa init-pki
./easyrsa build-ca nopass
./easyrsa gen-dh
openvpn --genkey --secret ta.key
./easyrsa gen-req server nopass
./easyrsa sign-req server server

echo "[5/9] Copying certs and keys to /etc/openvpn…"
cp -f pki/ca.crt pki/issued/server.crt pki/private/server.key pki/dh.pem ta.key /etc/openvpn/

echo "[6/9] Creating user/pass auth files…"
echo "testuser testpass" > /etc/openvpn/auth/psw-file
chmod 600 /etc/openvpn/auth/psw-file
cat <<'SH' > /etc/openvpn/auth/checkpsw.sh
#!/bin/sh
PASSFILE="/etc/openvpn/auth/psw-file"
CORRECT=$(grep "^$1 " "$PASSFILE" | cut -d' ' -f2-)
[ "$2" = "$CORRECT" ] && exit 0 || exit 1
SH
chmod 700 /etc/openvpn/auth/checkpsw.sh

echo "[7/9] Writing server.conf…"
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

echo "[8/9] Enabling and starting OpenVPN service…"
systemctl enable openvpn@server
systemctl restart openvpn@server

echo "[9/9] Enabling and starting vnStat service…"
systemctl enable vnstat
systemctl restart vnstat

EXIT_CODE=$?
echo "✅ Deployment finished with code: $EXIT_CODE"
echo "EXIT_CODE:$EXIT_CODE"
exit $EXIT_CODE

BASH;

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

            $output = '';
            $error = '';
            while (!feof($pipes[1]) || !feof($pipes[2])) {
                foreach ([1, 2] as $i) {
                    $line = fgets($pipes[$i]);
                    if ($line !== false) {
                        Log::info("DEPLOY_JOB: Got line from pipe $i: " . $line);
                        $this->vpnServer->appendLog(rtrim($line, "\r\n"));
                        if ($i === 1) {
                            $output .= $line;
                        } else {
                            $error .= $line;
                        }
                    }
                }
            }

            fclose($pipes[1]);
            fclose($pipes[2]);
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
