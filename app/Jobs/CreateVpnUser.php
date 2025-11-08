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

    // WireGuard pool for wireguard_address
    private string $wgSubnetCidr = '10.66.66.0/24';

    public int $tries   = 2;
    public int $timeout = 120;

    public function __construct(
        string $username,
        array $serverIds,
        ?int $clientId = null,
        ?string $password = null
    ) {
        $this->onConnection('redis');
        $this->onQueue('default');

        $this->username  = $username;
        $this->serverIds = $serverIds;
        $this->clientId  = $clientId;
        $this->password  = $password ?? Str::random(10);
    }

    public function handle(): void
    {
        Log::info("Creating VPN user: {$this->username}");

        if (VpnUser::where('username', $this->username)->exists()) {
            Log::error("Username '{$this->username}' already exists.");
            throw new \RuntimeException("Username '{$this->username}' already exists.");
        }

        /** @var VpnUser $vpnUser */
        $vpnUser = VpnUser::create([
            'username'       => $this->username,
            'plain_password' => $this->password,
            'client_id'      => $this->clientId,
            // password hashing handled by model mutator
        ]);

        // Attach servers
        $vpnUser->vpnServers()->sync($this->serverIds);
        $vpnUser->load('vpnServers');

        Log::info("VPN user {$vpnUser->username} attached to servers: " .
            $vpnUser->vpnServers->pluck('id')->join(', '));

        // Ensure WG identity (keys + /32) exists
        $this->ensureWireGuardIdentity($vpnUser);

        // Per-server OpenVPN artifacts + sync
        foreach ($vpnUser->vpnServers as $server) {
            GenerateOvpnFile::dispatch($vpnUser, $server)->onQueue('ovpn');
            SyncOpenVPNCredentials::dispatch($server)->onQueue('ovpn');
        }

        // Push WG peers for all linked servers
        AddWireGuardPeer::dispatch($vpnUser)->onQueue('wg');

        Log::info("Finished creating VPN user {$this->username} with OpenVPN + WireGuard.");
    }

    /**
     * Idempotent: only fills missing WG fields.
     */
    private function ensureWireGuardIdentity(VpnUser $user): void
    {
        $changed = false;

        // Keys
        if (blank($user->wireguard_private_key) || blank($user->wireguard_public_key)) {
            $keys = VpnUser::generateWireGuardKeys();
            $user->wireguard_private_key = $keys['private'];
            $user->wireguard_public_key  = $keys['public'];
            $changed = true;
            Log::info("Generated WG keypair for user {$user->id}");
        }

        // Address (/32 inside pool)
        if (blank($user->wireguard_address)) {
            $addr = $this->allocateWireGuardAddress();
            $user->wireguard_address = $addr;
            $changed = true;
            Log::info("Assigned WG address {$addr} to user {$user->id}");
        }

        if ($changed) {
            $user->save();
        } else {
            Log::info("WireGuard identity already present for user {$user->id}, no changes.");
        }
    }

    /**
     * Allocate next free /32 from $wgSubnetCidr based on vpn_users.wireguard_address.
     */
    private function allocateWireGuardAddress(): string
    {
        [$net, $maskBits] = explode('/', $this->wgSubnetCidr);
        $maskBits = (int) $maskBits;

        $netLong = ip2long($net);
        if ($netLong === false) {
            throw new \RuntimeException("Invalid WG subnet: {$this->wgSubnetCidr}");
        }

        // inclusive usable range; reserve .0, .1, .255
        $start = $netLong + 2;
        $end   = $netLong + (1 << (32 - $maskBits)) - 2;

        $used = VpnUser::whereNotNull('wireguard_address')
            ->pluck('wireguard_address')
            ->map(function ($cidr) {
                $ip = trim((string) $cidr);
                $ip = strtok($ip, '/');
                return $ip ? ip2long($ip) : null;
            })
            ->filter()
            ->all();

        $usedSet = array_flip($used);

        for ($ip = $start; $ip <= $end; $ip++) {
            if (!isset($usedSet[$ip])) {
                return long2ip($ip) . '/32';
            }
        }

        throw new \RuntimeException('No free WireGuard IPs available in pool ' . $this->wgSubnetCidr);
    }
}