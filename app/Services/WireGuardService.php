<?php

namespace App\Services;

use App\Models\VpnServer;
use App\Models\VpnUser;
use App\Models\WireguardPeer;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

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

        // Must have a WG identity at user level (for now). In future you can
        // generate this here and also store it to vpn_users.
        if (blank($vpnUser->wireguard_private_key) ||
            blank($vpnUser->wireguard_public_key) ||
            blank($vpnUser->wireguard_address)) {
            throw new InvalidArgumentException("VpnUser {$vpnUser->id} has no WireGuard identity (keys/address).");
        }

        // Existing non-revoked peer?
        $peer = WireguardPeer::where('vpn_server_id', $server->id)
            ->where('vpn_user_id', $vpnUser->id)
            ->where('revoked', false)
            ->first();

        if ($peer) {
            return $peer;
        }

        // Use the user's WG address (/32) as peer IP on this server
        $clientIp = strtok($vpnUser->wireguard_address, '/');
        if (! $clientIp) {
            throw new InvalidArgumentException("Invalid wireguard_address on user {$vpnUser->id}");
        }

        // Encrypt private key for storage on peer (future-proof)
        try {
            $encryptedPrivate = Crypt::encryptString($vpnUser->wireguard_private_key);
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
            'public_key'            => $vpnUser->wireguard_public_key,
            'preshared_key'         => null, // you can wire this up later if you decide to use PSKs
            'private_key_encrypted' => $encryptedPrivate,
            'ip_address'            => $clientIp,
            'allowed_ips'           => $clientIp . '/32',   // server side: what IPs this peer can send
            'dns'                   => $server->dns ?: null,
            'revoked'               => false,
        ]);

        $peer->save();

        // Try to push peer on server; log error but do not explode
        $this->addPeerOnServer($server, $peer);

        return $peer;
    }

    /**
     * Push peer to server's wg0 via SSH.
     * Does NOT throw on failure; logs and returns.
     */
    protected function addPeerOnServer(VpnServer $server, WireguardPeer $peer): void
    {
        $interface  = 'wg0'; // change if you use a different interface
        $allowedIps = $peer->allowed_ips ?: ($peer->ip_address . '/32');

        $cmd = sprintf(
            'sudo wg set %s peer %s allowed-ips %s',
            escapeshellarg($interface),
            escapeshellarg($peer->public_key),
            escapeshellarg($allowedIps),
            escapeshellarg($interface)
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
     * Uses user-level keys if present, otherwise falls back to decrypted
     * peer private key so you can drop raw keys from vpn_users later.
     */
    public function buildClientConfig(VpnServer $server, WireguardPeer $peer): string
    {
        $vpnUser = $peer->vpnUser;

        if (! $vpnUser) {
            throw new InvalidArgumentException("WireguardPeer {$peer->id} has no vpnUser relation.");
        }

        // Prefer user-level key for now…
        $privateKey = $vpnUser->wireguard_private_key;

        // …but fall back to encrypted peer copy if user-level fields are ever removed.
        if (blank($privateKey) && ! blank($peer->private_key_encrypted)) {
            try {
                $privateKey = Crypt::decryptString($peer->private_key_encrypted);
            } catch (\Throwable $e) {
                Log::error('❌ WG: Failed to decrypt peer private key', [
                    'peer_id'    => $peer->id,
                    'server_id'  => $server->id,
                    'vpn_user_id'=> $vpnUser->id,
                    'error'      => $e->getMessage(),
                ]);
            }
        }

        if (blank($privateKey) || blank($vpnUser->wireguard_address)) {
            throw new InvalidArgumentException("VpnUser {$vpnUser->id} missing WG identity.");
        }

        $endpoint   = $server->wgEndpoint(); // host:port
        $dns        = $peer->dns ?: ($server->dns ?: '1.1.1.1');
        $allowedIps = '0.0.0.0/0, ::/0';     // client: full tunnel
        $clientIpWithMask = $vpnUser->wireguard_address;

        $lines = [
            '[Interface]',
            'PrivateKey = ' . $privateKey,
            'Address = ' . $clientIpWithMask,
            'DNS = ' . $dns,
            '',
            '[Peer]',
            'PublicKey = ' . $server->wg_public_key,
            // no PSK yet
            'AllowedIPs = ' . $allowedIps,
            'Endpoint = ' . $endpoint,
            'PersistentKeepalive = 25',
        ];

        return implode("\n", $lines) . "\n";
    }

    /**
     * Optional: sync stats from `wg show wg0 dump` into wireguard_peers.
     */
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

        // wg show wg0 dump format:
        // line 0: interface line
        // others: pubkey, preshared_key, endpoint, allowed_ips, latest_handshake, rx, tx, persistent_keepalive
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

            /** @var WireguardPeer|null $peer */
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
}