<?php

namespace App\Jobs;

use App\Models\VpnUser;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class RemoveWireGuardPeer implements ShouldQueue
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
        Log::info("🔧 Removing WireGuard peer for user: {$this->vpnUser->username}");

        // Validate user has WireGuard public key
        if (empty($this->vpnUser->wireguard_public_key)) {
            Log::error("❌ [WG] Missing WireGuard public key for {$this->vpnUser->username}, cannot remove peer.");
            return;
        }

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
     * @param object $server
     * @return void
     */
    protected function removePeerFromServer($server): void
    {
        Log::info("🔧 Removing WireGuard peer from server: {$server->name} ({$server->ip_address})");

        // Remove peer from server
        $result = $this->executeRemoteCommand(
            $server->ip_address,
            $this->buildRemovePeerCommand($this->vpnUser->wireguard_public_key)
        );

        if ($result['status'] !== 0) {
            Log::error("❌ [WG] Failed to remove peer for {$this->vpnUser->username} from {$server->name}");
            Log::error("Error: " . implode("\n", $result['output']));
            return;
        }

        Log::info("✅ [WG] Successfully removed peer for {$this->vpnUser->username} from {$server->name}");
    }

    /**
     * Build the WireGuard command to remove a peer.
     *
     * @param string $publicKey
     * @return string
     */
    private function buildRemovePeerCommand(string $publicKey): string
    {
        $interface = 'wg0'; // Default WireGuard interface

        // Command to remove peer by public key
        $wgCommand = "wg set $interface peer $publicKey remove";

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
