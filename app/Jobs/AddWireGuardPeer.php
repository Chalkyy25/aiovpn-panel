<?php

namespace App\Jobs;

use App\Models\VpnUser;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class AddWireGuardPeer implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected VpnUser $vpnUser;
    protected $server = null;

    /**
     * Create a new job instance.
     *
     * @param VpnUser $vpnUser
     * @param object|null $server
     */
    public function __construct(VpnUser $vpnUser, $server = null)
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
            $this->addPeerToServer($this->server, $userKeys);
            return;
        }

        // Otherwise, add peer to all servers associated with the user
        if ($this->vpnUser->vpnServers->isEmpty()) {
            Log::warning("⚠️ No VPN servers associated with user {$this->vpnUser->username}");
            return;
        }

        foreach ($this->vpnUser->vpnServers as $server) {
            $this->addPeerToServer($server, $userKeys);
        }

        Log::info("✅ Completed WireGuard peer setup for user: {$this->vpnUser->username}");
    }

    /**
     * Add WireGuard peer to a specific server.
     *
     * @param object $server
     * @param array $userKeys
     * @return void
     */
    protected function addPeerToServer($server, array $userKeys): void
    {
        Log::info("🔧 Adding WireGuard peer to server: {$server->name} ({$server->ip_address})");

        // Create peer configuration
        $peerConfig = [
            'PublicKey' => $userKeys['wireguard_public_key'],
            'AllowedIPs' => $userKeys['wireguard_address'] . '/32',
        ];

        // Add peer to server
        $result = $this->executeRemoteCommand(
            $server->ip_address,
            $this->buildAddPeerCommand($peerConfig)
        );

        if ($result['status'] !== 0) {
            Log::error("❌ [WG] Failed to add peer for {$this->vpnUser->username} on {$server->name}");
            Log::error("Error: " . implode("\n", $result['output']));
            return;
        }

        Log::info("✅ [WG] Successfully added peer for {$this->vpnUser->username} on {$server->name}");
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

        // Save configuration permanently
        $saveCommand = "wg-quick save $interface";

        return "$wgCommand && $saveCommand";
    }

    /**
     * Execute a command on a remote server via SSH.
     *
     * @param string $ip
     * @param string $command
     * @return array
     */
    private function executeRemoteCommand(string $ip, string $command): array
    {
        $sshKey = storage_path('app/ssh_keys/id_rsa');
        $sshUser = 'root';
        $sshCommand = "ssh -i $sshKey -o StrictHostKeyChecking=no $sshUser@$ip '$command'";

        exec($sshCommand, $output, $status);

        return [
            'status' => $status,
            'output' => $output,
        ];
    }
}
