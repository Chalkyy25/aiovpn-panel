<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use phpseclib3\Net\SSH2;
use phpseclib3\Crypt\PublicKeyLoader;
use App\Models\VpnServer;

class TestSshConnection extends Command
{
    protected $signature = 'vpn:test-ssh {serverId}';
    protected $description = 'Test SSH connection to a VPN server and run uptime';

    public function handle()
    {
        $server = VpnServer::find($this->argument('serverId'));

        if (!$server) {
            $this->error('Server not found.');
            return;
        }

        $ssh = new SSH2($server->ip_address, $server->ssh_port);

        if ($server->ssh_type === 'password') {
            $success = $ssh->login($server->ssh_user, $server->ssh_password);
        } else {
            $keypath = $server->ssh_key_path ?: storage_path('app/ssh_keys/id_rsa');
            $key = PublicKeyLoader::load(file_get_contents($keypath));
            $success = $ssh->login($server->ssh_user, $key);
        }

        if (!$success) {
            $this->error('❌ SSH login failed');
            return;
        }

        $output = $ssh->exec('uptime');
        $this->info("✅ Connected! Uptime: $output");
    }
}
