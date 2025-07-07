<?php

namespace App\Jobs;

use App\Models\VpnUser;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class AddWireGuardPeer implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public VpnUser $vpnUser;

    public function __construct(VpnUser $vpnUser)
    {
        $this->vpnUser = $vpnUser;
    }

    public function handle(): void
    {
        Log::info("🚀 Adding WireGuard peers for user {$this->vpnUser->username}");

        foreach ($this->vpnUser->vpnServers as $server) {
            Log::info("🔧 Adding peer on server: {$server->name} ({$server->ip_address})");

            $ip = $server->ip_address;
            $port = $server->ssh_port ?? 22;
            $user = $server->ssh_user;
            $keyPath = '/var/www/aiovpn/storage/app/ssh_keys/id_rsa';

            $wgConfigCommand = sprintf(
                "sudo wg set wg0 peer %s allowed-ips %s",
                escapeshellarg($this->vpnUser->wireguard_public_key),
                escapeshellarg($this->vpnUser->wireguard_address)
            );

            $sshCmd = "ssh -i $keyPath -p $port -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null $user@$ip \"$wgConfigCommand\"";

            $output = shell_exec($sshCmd);

            Log::info("✅ WireGuard peer added on {$server->name}: {$output}");
        }

        Log::info("🎉 Finished adding WireGuard peers for user {$this->vpnUser->username}");
    }
}
