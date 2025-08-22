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

    /**
     * Create a new job instance.
     *
     * @param VpnUser $vpnUser
     * @param VpnServer|null $server
     */
    public function __construct(VpnUser $vpnUser, ?VpnServer $server = null)
    {
        $this->vpnUser = $vpnUser->load('vpnServers');
        $this->server = $server;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info("🔧 Removing WireGuard peer for user: {$this->vpnUser->username}");

        // Validate user has WireGuard public key
        if (empty($this->vpnUser->wireguard_public_key)) {
            Log::error("❌ [WG] Missing WireGuard public key for {$this->vpnUser->username}, cannot remove peer.");
            return;
        }

        // Log the public key for debugging
        Log::info("🔑 [WG] Removing peer with public key: {$this->vpnUser->wireguard_public_key}");

        // If a specific server is provided, remove peer only from that server
        if ($this->server) {
            $this->removePeerFromServer($this->server);
            return;
        }

        // Otherwise, remove peer from all servers associated with the user
        if ($this->vpnUser->vpnServers->isEmpty()) {
            Log::warning("⚠️ No VPN servers associated with user {$this->vpnUser->username}");
            return;
        }

        foreach ($this->vpnUser->vpnServers as $server) {
            $this->removePeerFromServer($server);
        }

        Log::info("✅ Completed WireGuard peer removal for user: {$this->vpnUser->username}");
    }

    /**
     * Remove WireGuard peer from a specific server.
     *
     * @param VpnServer $server
     * @return void
     */
    protected function removePeerFromServer(VpnServer $server): void
    {
        Log::info("🔧 Removing WireGuard peer from server: $server->name ($server->ip_address)");

        // First verify if the peer exists on the server
        $verifyResult = $this->executeRemoteCommand(
            $server->ip_address,
            "wg show wg0 peers | grep -q '{$this->vpnUser->wireguard_public_key}' && echo 'PEER_EXISTS'"
        );

        $peerExists = false;
        if ($verifyResult['status'] === 0) {
            foreach ($verifyResult['output'] as $line) {
                if (str_contains($line, 'PEER_EXISTS')) {
                    $peerExists = true;
                    break;
                }
            }
        }

        if (!$peerExists) {
            Log::warning("⚠️ [WG] Peer for {$this->vpnUser->username} not found on $server->name, possibly already removed.");
            return;
        }

        // Remove peer from server
        $result = $this->executeRemoteCommand(
            $server->ip_address,
            $this->buildRemovePeerCommand($this->vpnUser->wireguard_public_key)
        );

        if ($result['status'] !== 0) {
            Log::error("❌ [WG] Failed to remove peer for {$this->vpnUser->username} from $server->name");
            Log::error("Error: " . implode("\n", $result['output']));
            return;
        }

        // Check for peer still exists message
        $peerStillExists = false;
        foreach ($result['output'] as $line) {
            if (str_contains($line, 'PEER_STILL_EXISTS')) {
                $peerStillExists = true;
                break;
            }
        }

        if ($peerStillExists) {
            Log::error("❌ [WG] Peer removal command executed but peer still exists for {$this->vpnUser->username} on $server->name");
            return;
        }

        Log::info("✅ [WG] Successfully removed peer for {$this->vpnUser->username} from $server->name");
    }

    /**
     * Build the WireGuard command to remove a peer.
     *
     * @param string $publicKey
     * @return string
     */
    private function buildRemovePeerCommand(string $publicKey): string
    {
        $publicKey = escapeshellarg(trim($publicKey));

        return <<<BASH
# Remove from live config
wg set wg0 peer $publicKey remove

# Remove from saved config
sed -i "/$publicKey/,+2d" /etc/wireguard/wg0.conf

# Restart interface
wg-quick down wg0 && wg-quick up wg0
BASH;
    }


    // executeRemoteCommand method moved to ExecutesRemoteCommands trait
}
