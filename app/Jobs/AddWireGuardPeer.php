<?php

namespace App\Jobs;

use App\Models\VpnUser;
use App\Models\VpnServer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class AddWireGuardPeer implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public VpnUser $vpnUser;
    public VpnServer $vpnServer;

    public function __construct(VpnUser $vpnUser, VpnServer $vpnServer)
    {
        $this->vpnUser = $vpnUser;
        $this->vpnServer = $vpnServer;
    }

    public function handle(): void
    {
        Log::info("🔧 Adding WireGuard peer for user {$this->vpnUser->username}");

        $ip = $this->vpnServer->ip_address;
        $port = $this->vpnServer->ssh_port ?? 22;
        $user = $this->vpnServer->ssh_user;
        $keyPath = '/var/www/aiovpn/storage/app/ssh_keys/id_rsa';

        $wgConfigCommand = "
sudo wg set wg0 peer {$this->vpnUser->wireguard_public_key} allowed-ips {$this->vpnUser->wireguard_address}
";

        $sshCmd = "ssh -i $keyPath -p $port -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null $user@$ip \"$wgConfigCommand\"";

        $output = shell_exec($sshCmd);

        Log::info("✅ WireGuard peer added for {$this->vpnUser->username}: {$output}");
    }
}
