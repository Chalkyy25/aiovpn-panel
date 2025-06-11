<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class DeployVpnServerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $server;

    public function __construct($server)
    {
        $this->server = $server; // includes IP, port, user, protocol, etc.
    }

    public function handle()
    {
        $ip = $this->server->ip;
        $port = $this->server->ssh_port ?? 22;
        $username = $this->server->ssh_user ?? 'root';
        $protocol = strtolower($this->server->protocol); // openvpn or wireguard

        $baseScriptPath = "/var/www/scripts"; // adjust if stored elsewhere
        $script = $protocol === 'wireguard' ? 'install-wireguard.sh' : 'install-openvpn.sh';

        $sshCommand = "ssh -i ~/.ssh/id_rsa -o StrictHostKeyChecking=no -p $port $username@$ip 'bash -s' < $baseScriptPath/$script";

        exec($sshCommand, $output, $return);

        if ($return === 0) {
            Log::info("✅ VPN Deployment Successful for $ip");
        } else {
            Log::error("❌ VPN Deployment Failed for $ip", $output);
        }
    }
}
