<?php

namespace App\Jobs;

use App\Jobs\AddWireGuardPeer;
use App\Jobs\GenerateOvpnFile;
use App\Jobs\SyncOpenVPNCredentials;
use App\Models\VpnUser;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CreateVpnUser implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $username;
    public ?string $password;
    public array $serverIds;
    public ?int $clientId;

    public function __construct(string $username, array $serverIds, ?int $clientId = null, ?string $password = null)
    {
        $this->username  = $username;
        $this->serverIds = $serverIds;
        $this->clientId  = $clientId;
        $this->password  = $password ?? Str::random(10);
    }

    public function handle(): void
    {
        Log::info("🚀 Creating VPN user: {$this->username}");

        if (VpnUser::where('username', $this->username)->exists()) {
            Log::error("❌ Username '{$this->username}' already exists.");
            throw new \RuntimeException("Username '{$this->username}' already exists.");
        }

        /** @var VpnUser $vpnUser */
        $vpnUser = VpnUser::create([
            'username'       => $this->username,
            'plain_password' => $this->password,
            'client_id'      => $this->clientId,
        ]);

        // attach servers via pivot
        $vpnUser->vpnServers()->sync($this->serverIds);
        $vpnUser->load('vpnServers');

        Log::info("✅ VPN user {$vpnUser->username} attached to servers: ".$vpnUser->vpnServers->pluck('id')->join(', '));

        // per-server OpenVPN artifacts + sync
        foreach ($vpnUser->vpnServers as $server) {
            GenerateOvpnFile::dispatch($vpnUser, $server);
            SyncOpenVPNCredentials::dispatch($server);
        }

        // one WG job handles all linked servers (Option A)
        AddWireGuardPeer::dispatch($vpnUser);

        Log::info("🎉 Finished creating VPN user {$this->username} with configs and WG peers queued.");
    }
}