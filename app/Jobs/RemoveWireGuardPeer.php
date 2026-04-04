<?php

namespace App\Jobs;

use App\Models\VpnServer;
use App\Models\VpnUser;
use App\Traits\ExecutesRemoteCommands;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class RemoveWireGuardPeer implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, ExecutesRemoteCommands;

    protected VpnUser $vpnUser;
    protected ?VpnServer $server = null;

    public function __construct(VpnUser $vpnUser, ?VpnServer $server = null)
    {
        $this->vpnUser = $vpnUser;
        $this->server = $server;
    }

    public function handle(): void
    {
        Log::channel('vpn')->info("WG REMOVE START user={$this->vpnUser->username}");

        if (empty($this->vpnUser->wireguard_public_key)) {
            Log::channel('vpn')->error("WG REMOVE FAIL user={$this->vpnUser->username} reason=no_public_key");
            return;
        }

        if ($this->server) {
            $this->removePeerFromServer($this->server);
            return;
        }

        $servers = $this->vpnUser->vpnServers()->get();

        if ($servers->isEmpty()) {
            Log::channel('vpn')->warning("WG REMOVE SKIP user={$this->vpnUser->username} reason=no_servers");
            return;
        }

        foreach ($servers as $server) {
            $this->removePeerFromServer($server);
        }

        Log::channel('vpn')->info("WG REMOVE COMPLETE user={$this->vpnUser->username}");
    }

    protected function removePeerFromServer(VpnServer $server): void
    {
        $publicKey = trim($this->vpnUser->wireguard_public_key);

        Log::channel('vpn')->info(
            "WG REMOVE TRY user={$this->vpnUser->username} server={$server->name} ip={$server->ip_address}"
        );

        // Check if peer exists
        $check = $this->executeRemoteCommand(
            $server,
            "wg show wg0 peers | grep -q '$publicKey' && echo FOUND || echo NOT_FOUND"
        );

        $exists = collect($check['output'] ?? [])->contains(fn ($l) => str_contains($l, 'FOUND'));

        if (!$exists) {
            Log::channel('vpn')->warning(
                "WG REMOVE SKIP user={$this->vpnUser->username} server={$server->name} reason=not_found"
            );
            return;
        }

        // Remove peer (LIVE ONLY)
        $cmd = "wg set wg0 peer " . escapeshellarg($publicKey) . " remove";

        $result = $this->executeRemoteCommand($server, $cmd);

        if (($result['status'] ?? 1) !== 0) {
            Log::channel('vpn')->error(
                "WG REMOVE FAIL user={$this->vpnUser->username} server={$server->name} status={$result['status']}"
            );

            Log::channel('vpn')->error("WG REMOVE OUTPUT: " . implode("\n", $result['output'] ?? []));
            return;
        }

        // Remove from config (optional but recommended)
        $this->executeRemoteCommand(
            $server,
            "sed -i '/$publicKey/,+2d' /etc/wireguard/wg0.conf"
        );

        Log::channel('vpn')->info(
            "WG REMOVE SUCCESS user={$this->vpnUser->username} server={$server->name}"
        );
    }
}