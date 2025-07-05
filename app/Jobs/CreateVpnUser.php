<?php

namespace App\Jobs;

use App\Models\VpnUser;
use App\Models\VpnServer;
use App\Services\VpnConfigBuilder;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class CreateVpnUser implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $username;
    public ?string $password;
    public int $vpnServerId;
    public ?int $clientId;

    /**
     * Create a new job instance.
     */
    public function __construct(string $username, ?int $vpnServerId = null, ?int $clientId = null, ?string $password = null)
    {
        $this->username = $username;
        $this->vpnServerId = $vpnServerId ?? 1; // Default to first server if not specified
        $this->clientId = $clientId;
        $this->password = $password ?? Str::random(10);
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info("🚀 Creating VPN user: {$this->username}");

        // Fetch server
        $server = VpnServer::findOrFail($this->vpnServerId);

        // Create user
        $vpnUser = VpnUser::create([
            'username' => $this->username,
            'password' => bcrypt($this->password), // Store hashed password for security
            'vpn_server_id' => $server->id,
            'client_id' => $this->clientId,
        ]);

        // Generate WireGuard keys (model event will handle it, but explicit call shown here if needed)
        if (!$vpnUser->wireguard_private_key || !$vpnUser->wireguard_public_key) {
            $keys = VpnUser::generateWireGuardKeys();
            $vpnUser->wireguard_private_key = $keys['private'];
            $vpnUser->wireguard_public_key = $keys['public'];
            $vpnUser->wireguard_address = '10.66.66.' . rand(2, 254) . '/32';
            $vpnUser->save();
        }

        // Generate configs for OVPN and WireGuard
        VpnConfigBuilder::generate($vpnUser); // OpenVPN config
        VpnConfigBuilder::generateWireGuard($vpnUser); // WireGuard config (method in VpnConfigBuilder)

        Log::info("✅ VPN user created successfully: {$vpnUser->username}");
    }
}
