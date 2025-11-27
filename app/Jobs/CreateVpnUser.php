<?php

namespace App\Jobs;

use App\Models\VpnUser;
use App\Services\WireGuardService;
use Carbon\Carbon;
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
    /** @var array<int> */
    public array $serverIds;
    public ?int $clientId;
    public ?Carbon $expiresAt;

    private string $wgSubnetCidr = '10.66.66.0/24';

    public int $tries   = 2;
    public int $timeout = 120;

    public function __construct(
        string $username,
        array $serverIds,
        ?int $clientId = null,
        ?string $password = null,
        ?Carbon $expiresAt = null
    ) {
        $this->onConnection('redis');
        $this->onQueue('default');

        $this->username  = $username;
        $this->serverIds = $serverIds;
        $this->clientId  = $clientId;
        $this->password  = $password ?? Str::random(10);
        $this->expiresAt = $expiresAt;
    }

    public function handle(): void
    {
        Log::info("Creating VPN user: {$this->username}");

        if (empty($this->serverIds)) {
            Log::warning("CreateVpnUser: no servers provided for {$this->username}, aborting.");
            return;
        }

        if (VpnUser::where('username', $this->username)->exists()) {
            throw new \RuntimeException("Username '{$this->username}' already exists.");
        }

        /** @var VpnUser $vpnUser */
        $vpnUser = VpnUser::create([
            'username'       => $this->username,
            'plain_password' => $this->password,
            'client_id'      => $this->clientId,
            'expires_at'     => $this->expiresAt, // <--- expiry saved here
        ]);

        // Attach servers
        $vpnUser->vpnServers()->sync($this->serverIds);
        $vpnUser->load('vpnServers');

        Log::info(sprintf(
            'VPN user %s attached to servers: %s',
            $vpnUser->username,
            $vpnUser->vpnServers->pluck('id')->implode(', ')
        ));

        // WG identity (keys + /32)
        $this->ensureWireGuardIdentity($vpnUser);

        // OpenVPN: generate .ovpn + sync creds per server
        foreach ($vpnUser->vpnServers as $server) {
            GenerateOvpnFile::dispatch($vpnUser, $server)->onQueue('ovpn');
            SyncOpenVPNCredentials::dispatch($server)->onQueue('ovpn');
        }

        // WireGuard: add peer per server (if supported)
        foreach ($vpnUser->vpnServers as $server) {
            if (! $server->supportsWireGuard()) {
                continue;
            }

            try {
                WireGuardService::ensurePeerForUser($server, $vpnUser);
                Log::info("WG peer added for user {$vpnUser->id} on server {$server->id}");
            } catch (\Throwable $e) {
                Log::error("WG peer creation failed on server {$server->id}: {$e->getMessage()}");
            }
        }

        Log::info("Finished creating VPN user {$this->username} with OpenVPN + WireGuard.");
    }

    /**
     * Ensure WG keys + /32 are set (idempotent).
     */
    private function ensureWireGuardIdentity(VpnUser $user): void
    {
        $changed = false;

        if (blank($user->wireguard_private_key) || blank($user->wireguard_public_key)) {
            $keys = VpnUser::generateWireGuardKeys();
            $user->wireguard_private_key = $keys['private'];
            $user->wireguard_public_key  = $keys['public'];
            $changed = true;
        }

        if (blank($user->wireguard_address)) {
            $user->wireguard_address = $this->allocateWireGuardAddress();
            $changed = true;
        }

        if ($changed) {
            $user->save();
        }
    }

    /**
     * Allocate next free /32 from WG pool.
     */
    private function allocateWireGuardAddress(): string
    {
        [$net, $maskBits] = explode('/', $this->wgSubnetCidr);
        $maskBits = (int) $maskBits;

        $netLong = ip2long($net);
        if ($netLong === false) {
            throw new \RuntimeException("Invalid WG subnet: {$this->wgSubnetCidr}");
        }

        $start = $netLong + 2;
        $end   = $netLong + (1 << (32 - $maskBits)) - 2;

        $used = VpnUser::whereNotNull('wireguard_address')
            ->pluck('wireguard_address')
            ->map(fn ($cidr) => ip2long(strtok($cidr, '/')))
            ->filter()
            ->all();

        $usedSet = array_flip($used);

        for ($ip = $start; $ip <= $end; $ip++) {
            if (! isset($usedSet[$ip])) {
                return long2ip($ip) . '/32';
            }
        }

        throw new \RuntimeException("No free WireGuard IPs in {$this->wgSubnetCidr}");
    }
}