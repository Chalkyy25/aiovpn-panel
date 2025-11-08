<?php

namespace App\Jobs;

use App\Jobs\AddWireGuardPeer;
use App\Jobs\GenerateOvpnFile;
use App\Jobs\SyncOpenVPNCredentials;
use App\Models\VpnUser;
use App\Models\WireguardIpAllocation; // if you have one; else see inline allocator below
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

    // Configure your WG subnet here
    private string $wgSubnetCidr = '10.66.66.0/24';

    public function __construct(string $username, array $serverIds, ?int $clientId = null, ?string $password = null)
    {
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

        // 1) Create base user
        /** @var VpnUser $vpnUser */
        $vpnUser = VpnUser::create([
            'username'       => $this->username,
            'plain_password' => $this->password,
            'client_id'      => $this->clientId,
        ]);

        // 2) Generate WireGuard keys + address synchronously
        $this->provisionWireGuardIdentity($vpnUser);

        // 3) Attach servers via pivot
        $vpnUser->vpnServers()->sync($this->serverIds);
        $vpnUser->load('vpnServers');

        Log::info("VPN user {$vpnUser->username} attached to servers: ".$vpnUser->vpnServers->pluck('id')->join(', '));

        // 4) Per-server OpenVPN artifacts + sync
        foreach ($vpnUser->vpnServers as $server) {
            GenerateOvpnFile::dispatch($vpnUser, $server);
            SyncOpenVPNCredentials::dispatch($server);
        }

        // 5) WG peers on all linked servers (now we KNOW user has keys)
        AddWireGuardPeer::dispatch($vpnUser);

        Log::info("Finished creating VPN user {$this->username} with OpenVPN + WG provisioned.");
    }

    /**
     * Ensure the user has WireGuard keys + an address.
     * Idempotent: does nothing if already set.
     */
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

    /**
     * Generate a WireGuard keypair.
     * Option A: via `wg` binary.
     * Option B: via libsodium if you prefer pure PHP.
     */
    private function generateWireGuardKeypair(): array
{
    // Use full path and capture stderr for debugging
    $cmdPriv = '/usr/bin/wg genkey 2>&1';
    $privOut = shell_exec($cmdPriv);

    if ($privOut === null) {
        // shell_exec disabled or fatal
        throw new \RuntimeException('Failed to generate WireGuard private key: shell_exec returned null');
    }

    $priv = trim($privOut);

    // Basic sanity: wg keys are base64-ish, not error text
    if ($priv === '' || stripos($priv, 'error') !== false || strlen($priv) < 40) {
        \Log::error('wg genkey failed or suspicious output', [
            'output' => $privOut,
            'cmd'    => $cmdPriv,
        ]);
        throw new \RuntimeException('Failed to generate WireGuard private key');
    }

    $cmdPub = 'echo ' . escapeshellarg($priv) . ' | /usr/bin/wg pubkey 2>&1';
    $pubOut = shell_exec($cmdPub);

    if ($pubOut === null) {
        throw new \RuntimeException('Failed to generate WireGuard public key: shell_exec returned null');
    }

    $pub = trim($pubOut);

    if ($pub === '' || stripos($pub, 'error') !== false || strlen($pub) < 40) {
        \Log::error('wg pubkey failed or suspicious output', [
            'output' => $pubOut,
            'cmd'    => $cmdPub,
        ]);
        throw new \RuntimeException('Failed to derive WireGuard public key');
    }

    return [$priv, $pub];
}

    private function allocateWireGuardAddress(): string
    {
        [$net, $maskBits] = explode('/', $this->wgSubnetCidr);
        $maskBits = (int) $maskBits;

        $netLong = ip2long($net);
        if ($netLong === false) {
            throw new \RuntimeException("Invalid WG subnet: {$this->wgSubnetCidr}");
        }

        // Start at +2 to skip gateway if you use .1 (adjust as needed)
        $start = $netLong + 2;
        $end   = $netLong + (1 << (32 - $maskBits)) - 2;

        $used = VpnUser::whereNotNull('wireguard_address')
            ->pluck('wireguard_address')
            ->map(function ($cidr) {
                return ip2long(strtok($cidr, '/'));
            })
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