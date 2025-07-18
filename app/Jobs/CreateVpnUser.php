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

    // Check if username already exists
    if (VpnUser::where('username', $this->username)->exists()) {
        Log::error("❌ Username '{$this->username}' already exists. Aborting user creation.");
        throw new \Exception("Username '{$this->username}' is already taken.");
    }

    // Fetch server
    $server = VpnServer::findOrFail($this->vpnServerId);

    // Create user
    $vpnUser = VpnUser::create([
        'username' => $this->username,
        'password' => bcrypt($this->password), // Store hashed password securely
        'vpn_server_id' => $server->id,
        'client_id' => $this->clientId,
    ]);

    Log::info("🔑 VPN user record created in DB: {$vpnUser->username}");

    // Generate WireGuard keys if not already set
    if (empty($vpnUser->wireguard_private_key) || empty($vpnUser->wireguard_public_key)) {
        $keys = VpnUser::generateWireGuardKeys();
        $vpnUser->wireguard_private_key = $keys['private'];
        $vpnUser->wireguard_public_key = $keys['public'];
        $vpnUser->wireguard_address = '10.66.66.' . rand(2, 254) . '/32';
        $vpnUser->save();

        Log::info("🔑 WireGuard keys generated and saved for {$vpnUser->username}");
    }

    // Generate OpenVPN and WireGuard configs
    VpnConfigBuilder::generate($vpnUser);
    VpnConfigBuilder::generateWireGuard($vpnUser);

    Log::info("✅ VPN user created successfully with configs: {$vpnUser->username}");
}