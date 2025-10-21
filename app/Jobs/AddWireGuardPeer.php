<?php

namespace App\Jobs;

use App\Models\VpnServer;
use App\Models\VpnUser;
use App\Traits\ExecutesRemoteCommands;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class AddWireGuardPeer implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, ExecutesRemoteCommands, Batchable;

    protected VpnUser $vpnUser;
    protected ?VpnServer $server;

    /** sensible defaults */
    public int $tries = 2;
    public int $timeout = 120;

    /**
     * @param  VpnUser       $vpnUser
     * @param  VpnServer|null $server  If provided, add peer only to this server
     */
    public function __construct(VpnUser $vpnUser, ?VpnServer $server = null)
    {
        // use 'wg' queue on Redis (no property redeclarations!)
        $this->onConnection('redis');
        $this->onQueue('wg');

        // Only eager-load servers if we weren't given a specific one
        $this->vpnUser = $server ? $vpnUser : $vpnUser->load('vpnServers');
        $this->server  = $server;
    }

    public function tags(): array
    {
        return [
            'wg',
            'user:'.$this->vpnUser->id,
            'server:'.($this->server->id ?? 'all'),
        ];
    }

    public function handle(): void
    {
        $u = $this->vpnUser;
        Log::info("🔧 [WG] Add peer for user={$u->username}");

        $pub  = $u->wireguard_public_key ?? null;
        $addr = $u->wireguard_address ?? null;

        if (!$pub || !$addr) {
            Log::error("❌ [WG] Missing keys/address for {$u->username} (public_key or address).");
            return;
        }

        /** @var Collection<int,VpnServer> $servers */
        $servers = $this->server
            ? collect([$this->server])
            : ($u->vpnServers ?? collect());

        if ($servers->isEmpty()) {
            Log::warning("⚠️ [WG] No servers associated with user {$u->username}.");
            return;
        }

        $ok = 0;
        foreach ($servers as $server) {
            $ok += $this->addPeerToServer($server, $pub, $addr) ? 1 : 0;
        }

        $total = $servers->count();
        if ($ok === $total) {
            Log::info("✅ [WG] Peer added/updated for {$u->username} on {$total}/{$total} server(s).");
        } else {
            Log::warning("⚠️ [WG] Partial success for {$u->username}: {$ok}/{$total} servers updated.");
        }
    }

    /**
     * Add (or update) the WireGuard peer on a given server.
     */
    protected function addPeerToServer(VpnServer $server, string $publicKey, string $address): bool
    {
        $host = $server->ip_address ?? $server->ip ?? $server->host ?? null;
        if (!$host) {
            Log::error("❌ [WG] Server {$server->id} has no SSH host field (ip_address/ip/host).");
            return false;
        }

        // Normalize address to a single /32
        $ipOnly = preg_replace('/\/\d+$/', '', trim($address));
        $ip32   = "{$ipOnly}/32";

        Log::info("🔧 [WG] Server={$server->name} ({$host}) user={$this->vpnUser->username} allowed-ips={$ip32}");

        $script = $this->buildAddPeerScript($publicKey, $ip32);

        // ExecutesRemoteCommands should accept either a host string or the model;
        // here we pass host for clarity.
        $res = $this->executeRemoteCommand($host, $script);

        if (($res['status'] ?? 1) !== 0) {
            $out = trim(implode("\n", (array)($res['output'] ?? [])));
            Log::error("❌ [WG] Failed on {$server->name} ({$host}). Exit={$res['status']}. Output:\n{$out}");
            return false;
        }

        Log::info("✅ [WG] Added/updated peer for {$this->vpnUser->username} on {$server->name}");
        return true;
    }

    /**
     * Remote script:
     *  - verifies wg0 exists
     *  - adds/updates the peer with the given allowed-ips
     *  - persists using wg-quick save (requires SaveConfig=true in wg0.conf)
     */
    private function buildAddPeerScript(string $publicKey, string $ip32): string
    {
        $PUB = escapeshellarg($publicKey);
        $IP  = escapeshellarg($ip32);

        return <<<BASH
set -euo pipefail
IFACE="wg0"
PUB={$PUB}
IP32={$IP}

# Ensure interface exists
if ! wg show "\$IFACE" >/dev/null 2>&1; then
  echo "[WG] Interface \$IFACE not up"; exit 1
fi

# Add or update peer idempotently
wg set "\$IFACE" peer "\$PUB" allowed-ips "\$IP32"

# Persist peers to disk safely (SaveConfig=true)
wg-quick save "\$IFACE" >/dev/null 2>&1 || true
BASH;
    }
}