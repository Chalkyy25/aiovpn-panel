<?php

namespace App\Jobs;

use App\Models\VpnServer;
use App\Models\VpnUser;
use App\Traits\ExecutesRemoteCommands;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class ReconcileWireGuardServer implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, ExecutesRemoteCommands;

    protected VpnServer $server;

    public int $tries = 2;
    public int $timeout = 180;

    public function __construct(VpnServer $server)
    {
        $this->server = $server;

        $this->onConnection('redis');
        $this->onQueue('wg');
    }

    public function handle(): void
    {
        Log::info("WG RECONCILE START server={$this->server->name} ip={$this->server->ip_address}");

        $users = VpnUser::query()
            ->whereHas('vpnServers', fn ($q) => $q->where('vpn_servers.id', $this->server->id))
            ->whereNotNull('wireguard_public_key')
            ->whereNotNull('wireguard_address')
            ->get(['id', 'username', 'wireguard_public_key', 'wireguard_address']);

        $dbPeers = [];
        foreach ($users as $user) {
            $pub = trim((string) $user->wireguard_public_key);
            $ip = preg_replace('/\/\d+$/', '', trim((string) $user->wireguard_address)) . '/32';

            if ($pub !== '') {
                $dbPeers[$pub] = $ip;
            }
        }

        $live = $this->executeRemoteCommand($this->server, 'wg show wg0 peers', 30);

        if (($live['status'] ?? 1) !== 0) {
            Log::error("WG RECONCILE FAIL server={$this->server->name} reason=list_peers_failed");
            if (!empty($live['stderr'])) {
                Log::error("WG RECONCILE STDERR server={$this->server->name}: " . implode("\n", $live['stderr']));
            }
            return;
        }

        $serverPeers = collect($live['output'] ?? [])
            ->map(fn ($line) => trim((string) $line))
            ->filter()
            ->values()
            ->all();

        $serverLookup = array_fill_keys($serverPeers, true);

        $added = 0;
        $removed = 0;

        // Add missing peers
        foreach ($dbPeers as $pub => $ip32) {
            if (isset($serverLookup[$pub])) {
                continue;
            }

            $script = $this->buildAddScript($pub, $ip32);
            $res = $this->executeRemoteCommand($this->server, $script, 30);

            if (($res['status'] ?? 1) !== 0) {
                Log::error("WG RECONCILE ADD FAIL server={$this->server->name} pub={$pub} ip={$ip32}");
                if (!empty($res['stderr'])) {
                    Log::error("WG RECONCILE ADD STDERR server={$this->server->name}: " . implode("\n", $res['stderr']));
                }
                continue;
            }

            $added++;
        }

        // Remove orphan peers
        foreach ($serverPeers as $pub) {
            if (isset($dbPeers[$pub])) {
                continue;
            }

            $script = $this->buildRemoveScript($pub);
            $res = $this->executeRemoteCommand($this->server, $script, 30);

            if (($res['status'] ?? 1) !== 0) {
                Log::error("WG RECONCILE REMOVE FAIL server={$this->server->name} pub={$pub}");
                if (!empty($res['stderr'])) {
                    Log::error("WG RECONCILE REMOVE STDERR server={$this->server->name}: " . implode("\n", $res['stderr']));
                }
                continue;
            }

            $removed++;
        }

        Log::info("WG RECONCILE COMPLETE server={$this->server->name} added={$added} removed={$removed} expected=" . count($dbPeers));
    }

    protected function buildAddScript(string $publicKey, string $ip32): string
    {
        $PUB = escapeshellarg(trim($publicKey));
        $IP = escapeshellarg(trim($ip32));

        return <<<BASH
set -euo pipefail
wg set wg0 peer {$PUB} allowed-ips {$IP} persistent-keepalive 25
wg-quick save wg0
echo ADDED
BASH;
    }

    protected function buildRemoveScript(string $publicKey): string
    {
        $PUB = escapeshellarg(trim($publicKey));

        return <<<BASH
set -euo pipefail
if wg show wg0 peers | grep -Fxq {$PUB}; then
  wg set wg0 peer {$PUB} remove
  wg-quick save wg0
  echo REMOVED
else
  echo NOT_FOUND
fi
BASH;
    }
}