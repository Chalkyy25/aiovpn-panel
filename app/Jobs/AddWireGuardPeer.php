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

    /** summary-only logging by default */
    protected bool $quiet = true;

    public int $tries   = 2;
    public int $timeout = 120;

    public function __construct(VpnUser $vpnUser, ?VpnServer $server = null)
    {
        $this->onConnection('redis');
        $this->onQueue('wg');

        // Only eager-load servers when none is provided
        $this->vpnUser = $server ? $vpnUser : $vpnUser->load('vpnServers');
        $this->server  = $server;
    }

    /** Allow callers to switch verbosity */
    public function setQuiet(bool $quiet = true): self
    {
        $this->quiet = $quiet;
        return $this;
    }

    public function tags(): array
    {
        return [
            'wg',
            'user:' . $this->vpnUser->id,
            'server:' . ($this->server ? $this->server->id : 'all'), // ← fix null deref
        ];
    }

    public function handle(): void
    {
        $u    = $this->vpnUser;
        $pub  = $u->wireguard_public_key ?: null;
        $addr = $u->wireguard_address ? trim((string)$u->wireguard_address) : null;

        if (!$pub || !$addr) {
            Log::warning("⚠️ [WG] Skipping {$u->username}: missing public_key or address.");
            return;
        }

        /** @var Collection<int,VpnServer> $servers */
        $servers = $this->server ? collect([$this->server]) : collect($u->vpnServers ?? []);
        $servers = $servers->filter();               // drop nulls just in case
        $total   = $servers->count();

        if ($total === 0) {
            Log::warning("⚠️ [WG] No servers linked to {$u->username}.");
            return;
        }

        $ok = 0;
        foreach ($servers as $server) {
            $ok += $this->addPeerToServer($server, $pub, $addr) ? 1 : 0;
        }

        // Single summary line per user
        if ($ok === $total) {
            Log::info("✅ [WG] {$u->username}: {$ok}/{$total} server(s) updated.");
        } else {
            Log::warning("⚠️ [WG] {$u->username}: partial success {$ok}/{$total}.");
        }
    }

    protected function addPeerToServer(VpnServer $server, string $publicKey, string $address): bool
    {
        // Normalize to /32
        $ipOnly = preg_replace('/\/\d+$/', '', $address);
        $ip32   = "{$ipOnly}/32";

        $script = $this->buildAddPeerScript($publicKey, $ip32);

        // Pass the model; ExecutesRemoteCommands expects VpnServer
        $res = $this->executeRemoteCommand($server, $script);

        if (($res['status'] ?? 1) !== 0) {
            $out = trim(implode("\n", (array)($res['output'] ?? [])));
            Log::error("❌ [WG] Add/update failed on {$server->name} ({$server->ip_address}). exit={$res['status']}\n{$out}");
            return false;
        }

        if (!$this->quiet) {
            Log::info("✅ [WG] {$this->vpnUser->username} on {$server->name} ({$ip32})");
        }

        return true;
    }

    private function buildAddPeerScript(string $publicKey, string $ip32): string
    {
        $PUB = escapeshellarg($publicKey);
        $IP  = escapeshellarg($ip32);

        return <<<BASH
set -euo pipefail
IFACE="wg0"
PUB={$PUB}
IP32={$IP}

if ! command -v wg >/dev/null 2>&1; then
  echo "NO_WG"; exit 2
fi
if ! wg show "\$IFACE" >/dev/null 2>&1; then
  echo "NO_IFACE"; exit 3
fi

# Idempotent add/update
wg set "\$IFACE" peer "\$PUB" allowed-ips "\$IP32"

# Persist peers (SaveConfig=true)
wg-quick save "\$IFACE" >/dev/null 2>&1 || true
echo "OK"
BASH;
    }
}