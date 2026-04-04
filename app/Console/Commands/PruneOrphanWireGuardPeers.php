<?php

namespace App\Console\Commands;

use App\Models\VpnServer;
use App\Models\VpnUser;
use App\Traits\ExecutesRemoteCommands;
use Illuminate\Console\Command;

class PruneOrphanWireGuardPeers extends Command
{
    use ExecutesRemoteCommands;

    protected $signature = 'wg:prune-orphan-peers
                            {--server= : Server ID, name, or IP}
                            {--dry : Audit only, do not remove}';

    protected $description = 'Remove WireGuard peers from servers when their public keys no longer exist in vpn_users.';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry');
        $serverOpt = $this->option('server');

        $servers = $serverOpt
            ? collect([$this->resolveServer($serverOpt)])->filter()
            : VpnServer::query()->orderBy('id')->get();

        if ($servers->isEmpty()) {
            $this->error('No servers found.');
            return self::FAILURE;
        }

        $validKeys = VpnUser::query()
            ->whereNotNull('wireguard_public_key')
            ->pluck('wireguard_public_key')
            ->map(fn ($k) => trim((string) $k))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $validLookup = array_fill_keys($validKeys, true);

        $stats = [
            'servers' => 0,
            'server_peers' => 0,
            'valid' => 0,
            'orphans' => 0,
            'removed' => 0,
            'failed' => 0,
        ];

        foreach ($servers as $server) {
            $stats['servers']++;

            $this->info("→ Server: {$server->name} ({$server->ip_address})");

            $list = $this->executeRemoteCommand($server, 'wg show wg0 peers', 30);

            if (($list['status'] ?? 1) !== 0) {
                $this->error("  Failed to list peers");
                if (!empty($list['stderr'])) {
                    $this->line(implode("\n", $list['stderr']));
                }
                $stats['failed']++;
                continue;
            }

            $peers = collect($list['output'] ?? [])
                ->map(fn ($line) => trim((string) $line))
                ->filter()
                ->values();

            if ($peers->isEmpty()) {
                $this->line('  No peers found.');
                continue;
            }

            foreach ($peers as $peerKey) {
                $stats['server_peers']++;

                if (isset($validLookup[$peerKey])) {
                    $stats['valid']++;
                    continue;
                }

                $stats['orphans']++;
                $this->warn("  ORPHAN: {$peerKey}");

                if ($dry) {
                    $this->line("  DRY: would remove {$peerKey}");
                    continue;
                }

                $remove = $this->executeRemoteCommand($server, $this->buildRemoveScript($peerKey), 30);

                if (($remove['status'] ?? 1) !== 0) {
                    $stats['failed']++;
                    $this->error("  Failed removing {$peerKey}");
                    if (!empty($remove['output'])) {
                        $this->line(implode("\n", $remove['output']));
                    }
                    if (!empty($remove['stderr'])) {
                        $this->line(implode("\n", $remove['stderr']));
                    }
                    continue;
                }

                $stats['removed']++;
                $this->info("  REMOVED: {$peerKey}");
            }
        }

        $this->newLine();
        $this->info('Summary');
        $this->line('-------');
        foreach ($stats as $k => $v) {
            $this->line(str_pad($k, 12) . ': ' . $v);
        }

        return self::SUCCESS;
    }

    protected function resolveServer(string $serverOpt): ?VpnServer
    {
        return VpnServer::query()
            ->when(
                is_numeric($serverOpt),
                fn ($q) => $q->where('id', (int) $serverOpt),
                fn ($q) => $q->where(function ($qq) use ($serverOpt) {
                    $qq->where('name', $serverOpt)
                        ->orWhere('ip_address', $serverOpt);
                })
            )
            ->first();
    }

    protected function buildRemoveScript(string $publicKey): string
    {
        $PUB = escapeshellarg(trim($publicKey));

        return <<<BASH
set -euo pipefail
IFACE="wg0"
PUB={$PUB}

if ! wg show "\$IFACE" peers | grep -Fxq "\$PUB"; then
  echo "NOT_FOUND"
  exit 0
fi

wg set "\$IFACE" peer "\$PUB" remove
wg-quick save "\$IFACE"

if wg show "\$IFACE" peers | grep -Fxq "\$PUB"; then
  echo "STILL_PRESENT"
  exit 4
fi

echo "REMOVED"
BASH;
    }
}