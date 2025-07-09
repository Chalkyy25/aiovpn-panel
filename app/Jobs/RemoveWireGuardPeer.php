<?php

namespace App\Jobs;

use App\Models\VpnUser;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RemoveWireGuardPeer implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public VpnUser $vpnUser;

    public function __construct(VpnUser $vpnUser)
    {
        $this->vpnUser = $vpnUser;
    }

    public function handle(): void
    {
        Log::info("🗑️ [WireGuard] Starting peer removal for user {$this->vpnUser->username}");

        foreach ($this->vpnUser->vpnServers as $server) {
            Log::info("🔧 [WireGuard] Removing peer from server: {$server->name} ({$server->ip_address})");

            $ip = $server->ip_address;
            $port = $server->ssh_port ?? 22;
            $sshUser = $server->ssh_user;
            $keyPath = '/var/www/aiovpn/storage/app/ssh_keys/id_rsa';

            // 🗑️ Remove peer from live WireGuard interface
            $removeCmd = sprintf(
                "sudo wg set wg0 peer %s remove",
                escapeshellarg($this->vpnUser->wireguard_public_key)
            );

            $sshRemoveCmd = "ssh -i $keyPath -p $port -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null $sshUser@$ip \"$removeCmd\"";
            shell_exec($sshRemoveCmd);

            Log::info("✅ [WireGuard] Peer removed live from {$server->name}");

            // 🗑️ Remove peer block from wg0.conf for persistence
            $sedCmd = sprintf(
                "sudo sed -i '/# %s/,+4d' /etc/wireguard/wg0.conf",
                $this->vpnUser->username
            );

            $sshSedCmd = "ssh -i $keyPath -p $port -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null $sshUser@$ip \"$sedCmd\"";
            shell_exec($sshSedCmd);

            Log::info("💾 [WireGuard] Config block removed from wg0.conf on {$server->name}");
        }

        // 🗑️ Optional: Delete user keys from DB
        $this->vpnUser->wireguard_private_key = null;
        $this->vpnUser->wireguard_public_key = null;
        $this->vpnUser->save();

        Log::info("🧹 [WireGuard] Cleared keys from database for {$this->vpnUser->username}");

        // 🗑️ Optional: Delete local config file
        $fileName = "{$this->vpnUser->username}_wg.conf";
        \Storage::disk('local')->delete("configs/{$fileName}");

        Log::info("🗑️ [WireGuard] Deleted local config file for {$this->vpnUser->username}");

        Log::info("🎉 [WireGuard] Finished peer removal for user {$this->vpnUser->username}");
    }
}
