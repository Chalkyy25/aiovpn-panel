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
    public array $serverIds;
    public ?int $clientId;

    public function __construct(string $username, array $serverIds, ?int $clientId = null, ?string $password = null)
    {
        $this->username = $username;
        $this->serverIds = $serverIds;
        $this->clientId = $clientId;
        $this->password = $password ?? Str::random(10);
    }

    public function handle(): void
    {
        Log::info("🚀 Creating VPN user: {$this->username}");

        if (VpnUser::where('username', $this->username)->exists()) {
            Log::error("❌ Username '{$this->username}' already exists.");
            throw new \Exception("Username '{$this->username}' already exists.");
        }

        // ✅ Create user
        $vpnUser = VpnUser::create([
            'username' => $this->username,
            'plain_password' => $this->password,
            'password' => bcrypt($this->password),
            'client_id' => $this->clientId,
        ]);

        // ✅ Attach to multiple servers
        $vpnUser->vpnServers()->attach($this->serverIds);
        $vpnUser->load('vpnServers');

        Log::info("✅ VPN user created with servers: " . implode(', ', $vpnUser->vpnServers->pluck('id')->toArray()));

        // 🔁 Loop servers and generate configs
        foreach ($vpnUser->vpnServers as $server) {
            dispatch(new \App\Jobs\AddWireGuardPeer($vpnUser));
            dispatch(new \App\Jobs\SyncOpenVPNCredentials($server));
            dispatch(new \App\Jobs\GenerateOvpnFile($vpnUser, $server));
        }

        // 🛠️ Generate configs locally (optional if handled in jobs above)
        VpnConfigBuilder::generate($vpnUser);
        VpnConfigBuilder::generateWireGuard($vpnUser);

        Log::info("🎉 Finished creating VPN user {$this->username} with config files.");
    }
}