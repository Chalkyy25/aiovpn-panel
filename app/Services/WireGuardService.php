<?php

namespace App\Services;

use App\Models\VpnUser;
use App\Models\WireguardPeer;
use App\Models\VpnServer;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Illuminate\Support\Facades\Log;

class WireGuardService
{
    /**
     * Ensure a WireGuard peer exists for this user+server.
     * If already exists and not revoked, return it, otherwise create + push to server.
     */
    public function ensurePeerForUser(VpnServer $server, VpnUser $vpnUser): WireguardPeer
{
    if (!$server->hasWireGuard()) {
        throw new InvalidArgumentException("Server {$server->id} has no WireGuard configuration.");
    }

    $peer = WireguardPeer::where('vpn_server_id', $server->id)
        ->where('vpn_user_id', $vpnUser->id)
        ->where('revoked', false)
        ->first();

    if ($peer) {
        return $peer;
    }

    [$privKey, $pubKey, $psk] = $this->generateKeys();
    $ip = $this->allocateIp($server);

    $peer = new WireguardPeer([
        'vpn_server_id'       => $server->id,
        'vpn_user_id'         => $vpnUser->id,
        'public_key'          => $pubKey,
        'preshared_key'       => $psk,
        'ip_address'          => $ip,
        'allowed_ips'         => '0.0.0.0/0, ::/0',
        'dns'                 => $server->dns ?: null,
        'revoked'             => false,
    ]);
    $peer->private_key = $privKey;
    $peer->save();

    $this->addPeerOnServer($server, $peer);

    return $peer;
}
    /**
     * Generate WireGuard private/public/preshared keys.
     * Here done locally; you could also do this on the node via SSH.
     */
    protected function generateKeys(): array
    {
        // Simple local generation using system wg tools if available.
        // Fallback to random strings if not.
        $priv = trim(shell_exec('wg genkey 2>/dev/null')) ?: Str::random(44);
        $pub  = trim(shell_exec("echo '{$priv}' | wg pubkey 2>/dev/null")) ?: Str::random(44);
        $psk  = trim(shell_exec('wg genpsk 2>/dev/null')) ?: Str::random(44);

        return [$priv, $pub, $psk];
    }

    /**
     * Naive IP allocator inside wg_subnet, skipping already used IPs.
     * Example wg_subnet: 10.66.66.0/24
     */
    protected function allocateIp(VpnServer $server): string
    {
        $subnet = $server->wg_subnet ?: '10.66.66.0/24';
        [$base, $cidr] = explode('/', $subnet);

        $parts = explode('.', $base);
        if (count($parts) !== 4) {
            throw new InvalidArgumentException("Invalid WireGuard subnet: {$subnet}");
        }

        [$a, $b, $c, $d] = array_map('intval', $parts);

        // gather used IPs
        $used = $server->wireguardPeers()
            ->pluck('ip_address')
            ->filter()
            ->values()
            ->all();

        // simple /24 allocator: x.x.x.2 .. x.x.x.250
        for ($host = 2; $host <= 250; $host++) {
            $candidate = "{$a}.{$b}.{$c}.{$host}";
            if (!in_array($candidate, $used, true)) {
                return $candidate;
            }
        }

        throw new InvalidArgumentException("No free IPs left in WireGuard subnet {$subnet}");
    }

    /**
     * Add peer on the actual WireGuard server via wg set.
     */
    protected function addPeerOnServer(VpnServer $server, WireguardPeer $peer): void
{
    $interface  = 'wg0'; // adjust if you actually use a different interface
    $allowedIps = $peer->allowed_ips ?: "{$peer->ip_address}/32";

    // Simple version: no preshared-key, just pubkey + allowed-ips.
    // We can add PSK later with a safer temp-file approach.
    $cmd = sprintf(
        'sudo wg set %s peer %s allowed-ips %s && sudo wg-quick save %s',
        escapeshellarg($interface),
        escapeshellarg($peer->public_key),
        escapeshellarg($allowedIps),
        escapeshellarg($interface)
    );

    // Run via your existing SSH helper
    $result = $server->executeRemoteCommand($server, 'bash -lc ' . escapeshellarg($cmd));

    $status = $result['status'] ?? 1;
    $out    = $result['output'] ?? [];
    $err    = $result['error'] ?? null;

    if ($status !== 0) {
        Log::error('❌ Failed to add WireGuard peer on server', [
            'server_id'   => $server->id,
            'server_name' => $server->name,
            'ip'          => $server->ip_address,
            'cmd'         => $cmd,
            'status'      => $status,
            'output'      => $out,
            'error'       => $err,
        ]);

        // DO NOT throw here; we still return config so you can debug node-side.
        return;
    }

    Log::info('✅ WireGuard peer added on server', [
        'server_id'   => $server->id,
        'server_name' => $server->name,
        'ip'          => $server->ip_address,
        'peer_ip'     => $peer->ip_address,
        'peer_id'     => $peer->id,
    ]);
}

    /**
     * Build WireGuard client config text for this peer.
     */
    public function buildClientConfig(VpnServer $server, WireguardPeer $peer): string
{
    $endpoint   = $server->wgEndpoint();       // host:port
    $dns        = $peer->dns ?: ($server->dns ?: '1.1.1.1');
    $allowedIps = $peer->allowed_ips ?: '0.0.0.0/0, ::/0';

    $clientIpWithMask = $peer->ip_address . '/32';

    $lines = [
        '[Interface]',
        'PrivateKey = ' . $peer->private_key,
        'Address = ' . $clientIpWithMask,
        'DNS = ' . $dns,
        '',
        '[Peer]',
        'PublicKey = ' . $server->wg_public_key,
        // 🔻 IMPORTANT: drop PSK for now; server doesn’t have it
        // $peer->preshared_key ? 'PresharedKey = ' . $peer->preshared_key : null,
        'AllowedIPs = ' . $allowedIps,
        'Endpoint = ' . $endpoint,
        'PersistentKeepalive = 25',
    ];

    // remove nulls
    $lines = array_values(array_filter($lines, fn($line) => !is_null($line)));

    return implode("\n", $lines) . "\n";
}
}