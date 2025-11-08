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

    // WG pool used for wireguard_address
    private string $wgSubnetCidr = '10.66.66.0/24';

    public function __construct(string $username, array $serverIds, ?int $clientId = null, ?string $password = null)
    {
        $this->onConnection('redis');
        $this->onQueue('default'); // or your preferred queue

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
            // hashed password handled by model mutator if you have it
        ]);

        // Attach servers
        $vpnUser->vpnServers()->sync($this->serverIds);
        $vpnUser->load('vpnServers');

        Log::info("VPN user {$vpnUser->username} attached to servers: ".$vpnUser->vpnServers->pluck('id')->join(', '));

        // Provision WG identity (keys + /32)
        $this->provisionWireGuardIdentity($vpnUser);

        // Generate OpenVPN configs + sync per server
        foreach ($vpnUser->vpnServers as $server) {
            GenerateOvpnFile::dispatch($vpnUser, $server)->onQueue('ovpn');
            SyncOpenVPNCredentials::dispatch($server)->onQueue('ovpn');
        }

        // Now that WG keys/address exist, push peers to all linked servers
        AddWireGuardPeer::dispatch($vpnUser)->onQueue('wg');

        Log::info("Finished creating VPN user {$this->username} with OpenVPN + WireGuard.");
    }

    private function provisionWireGuardIdentity(VpnUser $user): void
    {
        if ($user->wireguard_public_key && $user->wireguard_private_key && $user->wireguard_address) {
            Log::info("WireGuard identity already exists for user {$user->id}, skipping.");
            return;
        }

        [$private, $public] = $this->generateWireGuardKeypair();
        $address = $this->allocateWireGuardAddress();

        $user->wireguard_private_key = $private;
        $user->wireguard_public_key  = $public;
        $user->wireguard_address     = $address;
        $user->save();

        Log::info("Assigned WG identity for user {$user->id}: {$address}");
    }

    private function generateWireGuardKeypair(): array
    {
        // Prefer libsodium. Install php-sodium if missing.
        if (!extension_loaded('sodium')) {
            throw new \RuntimeException('libsodium extension not available for WireGuard key generation');
        }

        // WireGuard keys are Curve25519 (X25519). This pattern matches typical WG-compatible generation.
        $sk = random_bytes(SODIUM_CRYPTO_BOX_SECRETKEYBYTES);
        $pk = sodium_crypto_scalarmult_base($sk);

        $private = base64_encode($sk);
        $public  = base64_encode($pk);

        if (strlen($private) < 40 || strlen($public) < 40) {
            throw new \RuntimeException('Generated WireGuard keys look invalid');
        }

        return [$private, $public];
    }

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
            if (!isset($usedSet[$ip])) {
                return long2ip($ip).'/32';
            }
        }

        throw new \RuntimeException('No free WireGuard IPs available in pool '.$this->wgSubnetCidr);
    }
}