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

class AddWireGuardPeer implements ShouldQueue
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
        Log::info("🔧 Adding WireGuard peer for user: {$this->vpnUser->username}");

        // Validate user has required WireGuard keys
        $userKeys = $this->vpnUser->only(['wireguard_public_key', 'wireguard_address']);
        if (empty($userKeys['wireguard_public_key']) || empty($userKeys['wireguard_address'])) {
            Log::error("❌ [WG] Missing WireGuard keys for {$this->vpnUser->username}, cannot add peer.");
            return;
        }

        // If a specific server is provided, add peer only to that server
        if ($this->server) {
            $success = $this->addPeerToServer($this->server, $userKeys);

            // Log final status for single server case
            if ($success) {
                Log::info("✅ Completed WireGuard peer setup for user: {$this->vpnUser->username} on server: {$this->server->name}");
            } else {
                Log::error("❌ WireGuard peer setup failed for user: {$this->vpnUser->username} on server: {$this->server->name}");
            }

            return;
        }

        // Otherwise, add peer to all servers associated with the user
        if ($this->vpnUser->vpnServers->isEmpty()) {
            Log::warning("⚠️ No VPN servers associated with user {$this->vpnUser->username}");
            return;
        }

        $successCount = 0;
        $totalServers = $this->vpnUser->vpnServers->count();

        foreach ($this->vpnUser->vpnServers as $server) {
            $success = $this->addPeerToServer($server, $userKeys);
            if ($success) {
                $successCount++;
            }
        }

        $allSuccessful = ($successCount === $totalServers);
        $failedServers = $totalServers - $successCount;

        // Log completion message regardless of whether all servers succeeded
        Log::info("✅ Completed WireGuard peer setup for user: {$this->vpnUser->username}");

        // If there were failures, log them explicitly
        if (!$allSuccessful) {
            Log::warning("⚠️ WireGuard peer setup had partial failures for user: {$this->vpnUser->username} - Failed on $failedServers/$totalServers servers");
        }
    }

    /**
     * Add WireGuard peer to a specific server.
     *
     * @param VpnServer $server
     * @param array $userKeys
     * @return bool Whether the operation was successful
     */
    protected function addPeerToServer(VpnServer $server, array $userKeys): bool
    {
        Log::info("🔧 Adding WireGuard peer to server: $server->name ($server->ip_address)");

        // Create peer configuration
        $peerConfig = [
            'PublicKey' => $userKeys['wireguard_public_key'],
            'AllowedIPs' => $userKeys['wireguard_address'],
        ];

        // First, clean up any existing CIDR notation to get just the IP
        $ipOnly = preg_replace('/\/\d+$/', '', $peerConfig['AllowedIPs']);

        // Then apply a single /32 CIDR suffix
        $peerConfig['AllowedIPs'] = $ipOnly . '/32';

        Log::info("🔧 Configured AllowedIPs for WireGuard peer: {$peerConfig['AllowedIPs']}");

        // Add peer to server
        $result = $this->executeRemoteCommand(
            $server->ip_address,
            $this->buildAddPeerCommand($peerConfig)
        );

        if ($result['status'] !== 0) {
            Log::error("❌ [WG] Failed to add peer for {$this->vpnUser->username} on $server->name");
            $errorMsg = !empty($result['output']) ? implode("\n", $result['output']) : "SSH command failed with status code {$result['status']}";
            Log::error("Error: " . $errorMsg);
            return false;
        }

        Log::info("✅ [WG] Successfully added peer for {$this->vpnUser->username} on $server->name");
        return true;
    }

    /**
     * Build the WireGuard command to add a peer.
     *
     * @param array $peerConfig
     * @return string
     */
    private function buildAddPeerCommand(array $peerConfig): string
    {
        $interface = 'wg0'; // Default WireGuard interface
        $commands = ["wg set $interface"];

        // Add peer with public key and allowed IPs
        $commands[] = "peer {$peerConfig['PublicKey']}";
        $commands[] = "allowed-ips {$peerConfig['AllowedIPs']}";

        // Join commands with spaces
        $wgCommand = implode(' ', $commands);

        // Save configuration permanently - use correct WireGuard command
        $saveCommand = "wg showconf $interface > /etc/wireguard/$interface.conf";

        return "$wgCommand && $saveCommand";
    }

    // executeRemoteCommand method moved to ExecutesRemoteCommands trait
}
