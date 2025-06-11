<?php

namespace App\Jobs;

use App\Models\VpnServer;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Process;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DeployVpnServer implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    protected $server;

    public function __construct(VpnServer $server)
    {
        $this->server = $server;
    }

    public function handle(): void
    {
        $ip = $this->server->ip;
        $sshPort = $this->server->ssh_port;
        $username = $this->server->ssh_user;
        $password = $this->server->ssh_password;

        $script = <<<EOD
#!/bin/bash
apt update
apt install -y openvpn easy-rsa sshpass
mkdir -p /etc/openvpn/auth
echo "testuser testpass" > /etc/openvpn/auth/psw-file
chmod 400 /etc/openvpn/auth/psw-file
# Add more logic to copy config and enable OpenVPN
EOD;

        $escapedScript = escapeshellarg($script);

        $command = "sshpass -p '$password' ssh -o StrictHostKeyChecking=no -p $sshPort $username@$ip 'bash -s' <<< $escapedScript";

        $process = Process::timeout(300)->run($command);

        if ($process->successful()) {
            $this->server->update(['status' => 'deployed']);
        } else {
            $this->server->update(['status' => 'failed', 'logs' => $process->errorOutput()]);
        }
    }
}
