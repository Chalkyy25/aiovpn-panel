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
        $this->username   = $username;
        $this->serverIds  = $serverIds;
        $this->clientId   = $clientId;
        $this->password   = $password ?? Str::random(10);
    }

    public function handle(): void
    {
        Log::info("🚀 Creating VPN user: {$this->username}");

        if (VpnUser::where('username', $this->username)->exists()) {
            Log::error("❌ Username '{$this->username}' already exists.");
            throw new \Exception("Username '{$this->username}' already exists.");
        }

        // Create user (VpnUser mutators will hash plain_password)
        /** @var VpnUser $vpnUser */
        $vpnUser = VpnUser::create([
            'username'       => $this->username,
            'plain_password' => $this->password,
            'client_id'      => $this->clientId,
        ]);

        // Attach to servers via new pivot
        $vpnUser->vpnServers()->syncWithoutDetaching($this->serverIds);
        $vpnUser->load('vpnServers');

        Log::info("✅ VPN user created with servers: " . $vpnUser->vpnServers->pluck('id')->join(', '));

        foreach ($vpnUser->vpnServers as $server) {
            // Per-(user, server) jobs: keep signatures consistent with wg:generate and others
            AddWireGuardPeer::dispatch($vpnUser, $server)->onQueue('wg');
            SyncOpenVPNCredentials::dispatch($server);
            GenerateOvpnFile::dispatch($vpnUser, $server);
        }

        // If VpnConfigBuilder has a global helper, keep it per-server instead:
        // foreach ($vpnUser->vpnServers as $server) {
        //     VpnConfigBuilder::generate($vpnUser, $server);
        // }

        Log::info("🎉 Finished creating VPN user {$this->username} with server bindings.");
    }
}