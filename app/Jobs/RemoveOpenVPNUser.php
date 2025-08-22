<?php

namespace App\Jobs;

use App\Models\VpnUser;
use App\Models\VpnServer;
use App\Jobs\SyncOpenVPNCredentials;
use App\Traits\ExecutesRemoteCommands;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class RemoveOpenVPNUser implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, ExecutesRemoteCommands;

    protected VpnUser $vpnUser;
    protected ?VpnServer $server;

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
        Log::info("🔧 Removing OpenVPN files and credentials for user: {$this->vpnUser->username}");

        // If a specific server is provided, clean up only for that server
        if ($this->server) {
            $this->cleanupForServer($this->server);
            return;
        }

        // Otherwise, clean up for all servers associated with the user
        if ($this->vpnUser->vpnServers->isEmpty()) {
            Log::warning("⚠️ No VPN servers associated with user {$this->vpnUser->username}");
            return;
        }

        foreach ($this->vpnUser->vpnServers as $server) {
            $this->cleanupForServer($server);
        }

        Log::info("✅ Completed OpenVPN cleanup for user: {$this->vpnUser->username}");
    }

    /**
     * Clean up OpenVPN files and credentials for a specific server.
     *
     * @param VpnServer $server
     * @return void
     */
    protected function cleanupForServer(VpnServer $server): void
    {
        Log::info("🔧 Cleaning up OpenVPN for server: $server->name ($server->ip_address)");

        // ✅ SECURITY FIX: No config files to remove since we use on-demand generation
        Log::info("ℹ️ [OVPN] No config files to clean up (using on-demand generation)");

        // Regenerate OpenVPN credentials to remove this user
        $this->regenerateCredentials($server);
    }

    /**
     * Regenerate OpenVPN credentials on the server to remove this user.
     *
     * @param VpnServer $server
     * @return void
     */
    protected function regenerateCredentials(VpnServer $server): void
    {
        Log::info("🔄 [OpenVPN] Regenerating credentials for server: $server->name");

        // Dispatch the SyncOpenVPNCredentials job to regenerate the password file
        // This will automatically exclude the deleted user since they're no longer in the database
        SyncOpenVPNCredentials::dispatch($server);

        Log::info("✅ [OpenVPN] Credential regeneration queued for server: $server->name");
    }
}
