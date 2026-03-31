<?php

namespace App\Services;

use App\Models\VpnServer;
use App\Models\VpnUser;
use App\Models\WireguardPeer;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Symfony\Component\Process\Process;

class WireGuardService
{
    /**
     * Ensure a WireGuard peer exists for this user on this server.
     * Idempotent: returns existing peer if present.
     */
    public function ensurePeerForUser(VpnServer $server, VpnUser $vpnUser): WireguardPeer
    {
        if (! $server->supportsWireGuard()) {
            throw new InvalidArgumentException("Server {$server->id} has no WireGuard configuration.");
        }

        // ✅ Auto-generate identity if missing (fixes Filament-created users)
        $this->ensureIdentity($server, $vpnUser);
        $vpnUser->refresh();

        // Existing non-revoked peer?
        $peer = WireguardPeer::where('vpn_server_id', $server->id)
            ->where('vpn_user_id', $vpnUser->id)
            ->where('revoked', false)
            ->first();

        if ($peer) {
            return $peer;
        }

        // Use the user's WG address (/32) as peer IP on this server
        $clientIp = strtok((string) $vpnUser->wireguard_address, '/');
        if (! $clientIp) {
            throw new InvalidArgumentException("Invalid wireguard_address on user {$vpnUser->id}");
        }

        // Encrypt private key for storage on peer (future-proof)
        try {
            $encryptedPrivate = Crypt::encryptString((string) $vpnUser->wireguard_private_key);
        } catch (\Throwable $e) {
            Log::error('❌ WG: Failed to encrypt private key for peer', [
                'vpn_user_id' => $vpnUser->id,
                'server_id'   => $server->id,
                'error'       => $e->getMessage(),
            ]);
            throw new InvalidArgumentException("Could not encrypt WireGuard private key.");
        }

        $peer = new WireguardPeer([
            'vpn_server_id'         => $server->id,
            'vpn_user_id'           => $vpnUser->id,
            'public_key'            => (string) $vpnUser->wireguard_public_key,
            'preshared_key'         => null, // optional later
            'private_key_encrypted' => $encryptedPrivate,
            'ip_address'            => $clientIp,
            'allowed_ips'           => $clientIp . '/32',
            'dns'                   => $server->dns ?: null,
            'revoked'               => false,
        ]);

        $peer->save();

        // Try to push peer on server; log error but do not explode
        $this->addPeerOnServer($server, $peer);

        return $peer;
    }

    /**
     * Ensure the user has a WireGuard identity stored on vpn_users.
     * Generates keypair locally + allocates next free IP on that server.
     */
    protected function ensureIdentity(VpnServer $server, VpnUser $vpnUser): void
    {
        if (! blank($vpnUser->wireguard_private_key)
            && ! blank($vpnUser->wireguard_public_key)
            && ! blank($vpnUser->wireguard_address)) {
            return;
        }

        // 1) Generate keypair locally on panel (requires wireguard-tools)
        $private = trim($this->runLocal(['wg', 'genkey']));
        $public  = trim($this->runLocalWithInput(['wg', 'pubkey'], $private . "\n"));

        // 2) Detect server subnet from wg0.conf (Address = x.x.x.x/yy)
        $subnetCidr = $this->detectServerSubnetCidr($server); // e.g. 10.7.0.0/24

        // 3) Allocate next free client IP from existing peers
        $clientIp = $this->nextFreeIpForServer($server, $subnetCidr);

        $vpnUser->forceFill([
            'wireguard_private_key' => $private,
            'wireguard_public_key'  => $public,
            'wireguard_address'     => $clientIp . '/32',
        ])->save();

        Log::info('✅ WG: Generated identity for user', [
            'vpn_user_id' => $vpnUser->id,
            'server_id'   => $server->id,
            'address'     => $vpnUser->wireguard_address,
        ]);
    }

    /**
     * Reads: Address = 10.7.0.1/24 from /etc/wireguard/wg0.conf
     * Returns network CIDR: 10.7.0.0/24
     */
    protected function detectServerSubnetCidr(VpnServer $server): string
    {
        $cmd = "sudo sh -lc " . escapeshellarg("grep -E '^Address\\s*=' /etc/wireguard/wg0.conf | head -n1");
        $result = $server->executeRemoteCommand($server, 'bash -lc ' . escapeshellarg($cmd));

        $out = $result['output'] ?? [];
        $line = is_array($out) ? implode("\n", $out) : (string) $out;

        if (! preg_match('/Address\s*=\s*([0-9.]+)\/(\d+)/', $line, $m)) {
            throw new InvalidArgumentException("Could not detect WG Address from /etc/wireguard/wg0.conf on server {$server->id}");
        }

        $serverIp = $m[1];
        $mask     = (int) $m[2];

        $ipLong = ip2long($serverIp);
        if ($ipLong === false) {
            throw new InvalidArgumentException("Invalid WG Address IP on server {$server->id}");
        }

        // Support common setups only. If you use /32 or /20 etc, tell me and I’ll extend.
        if (! in_array($mask, [16, 24], true)) {
            throw new InvalidArgumentException("WG subnet mask /{$mask} not supported by allocator yet (server {$server->id})");
        }

        $netmaskLong = ($mask === 24) ? ip2long('255.255.255.0') : ip2long('255.255.0.0');
        $networkLong = $ipLong & $netmaskLong;

        return long2ip($networkLong) . "/{$mask}";
    }

    /**
     * Allocates next free IP in server subnet based on existing non-revoked peers.
     * Starts at .2 (keeps .1 for server).
     */
    protected function nextFreeIpForServer(VpnServer $server, string $subnetCidr): string
    {
        [$net, $mask] = explode('/', $subnetCidr);
        $mask = (int) $mask;

        $netLong = ip2long($net);
        if ($netLong === false) {
            throw new InvalidArgumentException("Invalid subnet {$subnetCidr}");
        }

        $start = $netLong + 2; // reserve .1 for server interface

        $end = match ($mask) {
            24 => $netLong + 254,
            16 => $netLong + 65534,
            default => throw new InvalidArgumentException("Unsupported subnet mask /{$mask}"),
        };

        $used = WireguardPeer::where('vpn_server_id', $server->id)
            ->where('revoked', false)
            ->pluck('ip_address')
            ->filter()
            ->all();

        $usedSet = array_fill_keys($used, true);

        for ($ip = $start; $ip <= $end; $ip++) {
            $candidate = long2ip($ip);
            if (! isset($usedSet[$candidate])) {
                return $candidate;
            }
        }

        throw new InvalidArgumentException("No free WireGuard IPs left in {$subnetCidr} for server {$server->id}");
    }

    /**
     * Push peer to server's wg0 via SSH.
     * Does NOT throw on failure; logs and returns.
     */
    protected function addPeerOnServer(VpnServer $server, WireguardPeer $peer): void
    {
        $interface  = 'wg0';
        $allowedIps = $peer->allowed_ips ?: ($peer->ip_address . '/32');

        // ✅ FIXED: removed extra sprintf arg
        $cmd = sprintf(
            'sudo wg set %s peer %s allowed-ips %s',
            escapeshellarg($interface),
            escapeshellarg($peer->public_key),
            escapeshellarg($allowedIps)
        );

        try {
            $result = $server->executeRemoteCommand($server, 'bash -lc ' . escapeshellarg($cmd));
        } catch (\Throwable $e) {
            Log::error('❌ WG: SSH error adding peer', [
                'server_id' => $server->id,
                'server_ip' => $server->ip_address,
                'peer_id'   => $peer->id,
                'exception' => $e->getMessage(),
            ]);
            return;
        }

        $status = $result['status'] ?? 1;
        $out    = $result['output'] ?? [];
        $err    = $result['error'] ?? null;

        if ($status !== 0) {
            Log::error('❌ WG: Failed to add peer on server', [
                'server_id' => $server->id,
                'server_ip' => $server->ip_address,
                'peer_id'   => $peer->id,
                'cmd'       => $cmd,
                'status'    => $status,
                'output'    => $out,
                'error'     => $err,
            ]);
            return;
        }

        Log::info('✅ WG: Peer added', [
            'server_id' => $server->id,
            'server_ip' => $server->ip_address,
            'peer_id'   => $peer->id,
            'peer_ip'   => $peer->ip_address,
        ]);
    }

    /**
     * Build client config (.conf) for mobile app.
     */
    public function buildClientConfig(VpnServer $server, WireguardPeer $peer): string
    {
        $vpnUser = $peer->vpnUser;

        if (! $vpnUser) {
            throw new InvalidArgumentException("WireguardPeer {$peer->id} has no vpnUser relation.");
        }

        $privateKey = $vpnUser->wireguard_private_key;

        if (blank($privateKey) && ! blank($peer->private_key_encrypted)) {
            try {
                $privateKey = Crypt::decryptString($peer->private_key_encrypted);
            } catch (\Throwable $e) {
                Log::error('❌ WG: Failed to decrypt peer private key', [
                    'peer_id'     => $peer->id,
                    'server_id'   => $server->id,
                    'vpn_user_id' => $vpnUser->id,
                    'error'       => $e->getMessage(),
                ]);
            }
        }

        if (blank($privateKey) || blank($vpnUser->wireguard_address)) {
            throw new InvalidArgumentException("VpnUser {$vpnUser->id} missing WG identity.");
        }

        $endpoint   = $server->wgEndpoint(); // host:port
        $dns        = $peer->dns ?: ($server->dns ?: '1.1.1.1');
        $allowedIps = '0.0.0.0/0, ::/0';
        $clientIpWithMask = $vpnUser->wireguard_address;
        $mtu = (int) ($server->mtu ?: 1340);

$lines = [
    '[Interface]',
    'PrivateKey = ' . $privateKey,
    'Address = ' . $clientIpWithMask,
    'DNS = ' . $dns,
    'MTU = ' . $mtu,
    '',
    '[Peer]',
    'PublicKey = ' . $server->wg_public_key,
    'AllowedIPs = ' . $allowedIps,
    'Endpoint = ' . $endpoint,
    'PersistentKeepalive = 25',
];

        return implode("\n", $lines) . "\n";
    }

    public function syncServerPeerStats(VpnServer $server): void
    {
        $interface = 'wg0';

        try {
            $result = $server->executeRemoteCommand(
                $server,
                'bash -lc ' . escapeshellarg('sudo wg show ' . escapeshellarg($interface) . ' dump')
            );
        } catch (\Throwable $e) {
            Log::error('❌ WG: Failed to run wg show dump', [
                'server_id' => $server->id,
                'error'     => $e->getMessage(),
            ]);
            return;
        }

        if (($result['status'] ?? 1) !== 0) {
            Log::warning('⚠️ WG: Non-zero status from wg show dump', [
                'server_id' => $server->id,
                'output'    => $result['output'] ?? [],
                'error'     => $result['error'] ?? null,
            ]);
            return;
        }

        $lines = $result['output'] ?? [];
        if (empty($lines)) {
            return;
        }

        foreach ($lines as $idx => $line) {
            $line = trim($line);
            if ($line === '' || $idx === 0) {
                continue;
            }

            $parts = explode("\t", $line);
            if (count($parts) < 7) {
                continue;
            }

            [$pubKey, $psk, $endpoint, $allowedIps, $handshake, $rx, $tx] = $parts;

            $peer = WireguardPeer::where('vpn_server_id', $server->id)
                ->where('public_key', $pubKey)
                ->first();

            if (! $peer) {
                continue;
            }

            $peer->transfer_rx_bytes = (int) $rx;
            $peer->transfer_tx_bytes = (int) $tx;
            $peer->allowed_ips       = $allowedIps;

            if ((int) $handshake > 0) {
                $peer->last_handshake_at = now()->subSeconds(
                    max(0, time() - (int) $handshake)
                );
            }

            $peer->save();
        }
    }

    protected function runLocal(array $cmd): string
    {
        $p = new Process($cmd);
        $p->run();

        if (! $p->isSuccessful()) {
            throw new InvalidArgumentException("WG command failed (" . implode(' ', $cmd) . '): ' . $p->getErrorOutput());
        }

        return (string) $p->getOutput();
    }

    protected function runLocalWithInput(array $cmd, string $input): string
    {
        $p = new Process($cmd);
        $p->setInput($input);
        $p->run();

        if (! $p->isSuccessful()) {
            throw new InvalidArgumentException("WG command failed (" . implode(' ', $cmd) . '): ' . $p->getErrorOutput());
        }

        return (string) $p->getOutput();
    }
}
