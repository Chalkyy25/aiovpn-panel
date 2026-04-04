<?php

namespace App\Console\Commands;

use App\Models\VpnServer;
use App\Models\VpnUser;
use App\Traits\ExecutesRemoteCommands;
use Illuminate\Console\Command;

class AuditRepairWireGuardPeers extends Command
{
    use ExecutesRemoteCommands;

    protected $signature = 'wg:audit-repair-peers
                            {--server= : Server ID, name, or IP}
                            {--only-active=1 : 1=only active users, 0=include all}
                            {--dry : Audit only, do not repair}
                            {--limit=0 : Limit number of users processed}';

    protected $description = 'Audit all WireGuard VPN users against server peers and repair mismatches.';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry');
        $onlyActive = (int) $this->option('only-active') === 1;
        $limit = (int) $this->option('limit');
        $serverOpt = $this->option('server');

        $targetServer = null;
        if ($serverOpt) {
            $targetServer = VpnServer::query()
                ->when(
                    is_numeric($serverOpt),
                    fn ($q) => $q->where('id', (int) $serverOpt),
                    fn ($q) => $q->where(function ($qq) use ($serverOpt) {
                        $qq->where('name', $serverOpt)
                           ->orWhere('ip_address', $serverOpt);
                    })
                )
                ->first();

            if (!$targetServer) {
                $this->error("Server not found: {$serverOpt}");
                return self::FAILURE;
            }
        }

        $query = VpnUser::query()
            ->when($onlyActive, fn ($q) => $q->where('is_active', true))
            ->whereNotNull('wireguard_public_key')
            ->whereNotNull('wireguard_address')
            ->with($targetServer ? [] : ['vpnServers']);

        if ($limit > 0) {
            $query->limit($limit);
        }

        $users = $query->orderBy('id')->get();

        if ($users->isEmpty()) {
            $this->warn('No matching users found.');
            return self::SUCCESS;
        }

        $stats = [
            'users' => 0,
            'server_checks' => 0,
            'correct' => 0,
            'repaired' => 0,
            'missing_added' => 0,
            'failed' => 0,
            'skipped' => 0,
        ];

        foreach ($users as $user) {
            $stats['users']++;

            $pub = trim((string) $user->wireguard_public_key);
            $addr = trim((string) $user->wireguard_address);

            if ($pub === '' || $addr === '') {
                $stats['skipped']++;
                continue;
            }

            if (!blank($user->wireguard_private_key)) {
                $derived = trim(shell_exec("printf '%s' ".escapeshellarg($user->wireguard_private_key)." | wg pubkey"));
                if ($derived !== $pub) {
                    $this->warn("Skipping user {$user->id} ({$user->username}): DB keypair mismatch.");
                    $stats['skipped']++;
                    continue;
                }
            }

            $ipOnly = preg_replace('/\/\d+$/', '', $addr);
            $ip32 = "{$ipOnly}/32";

            $servers = $targetServer
                ? collect([$targetServer])
                : collect($user->vpnServers ?? [])->filter();

            if ($servers->isEmpty()) {
                $this->warn("Skipping user {$user->id} ({$user->username}): no linked servers.");
                $stats['skipped']++;
                continue;
            }

            foreach ($servers as $server) {
                $stats['server_checks']++;

                $this->line("User {$user->id} {$user->username} -> {$server->name} ({$ip32})");

                $auditScript = $this->buildAuditScript($ip32);
                $audit = $this->executeRemoteCommand($server, $auditScript);

                if (($audit['status'] ?? 1) !== 0) {
                    $this->error("  Audit failed on {$server->name}");
                    $stats['failed']++;
                    continue;
                }

                $output = implode("\n", (array) ($audit['output'] ?? []));
                preg_match('/CURRENT_KEY=(.*)/', $output, $matches);
                $currentKey = trim($matches[1] ?? '');

                if ($currentKey === $pub) {
                    $this->info("  OK: matches DB key");
                    $stats['correct']++;
                    continue;
                }

                if ($currentKey === 'NONE' || $currentKey === '') {
                    $this->warn("  Missing peer on server");
                    if ($dry) {
                        $this->line("  DRY: would add {$pub}");
                        continue;
                    }

                    $repair = $this->executeRemoteCommand($server, $this->buildRepairScript($pub, $ip32));
                    if (($repair['status'] ?? 1) !== 0) {
                        $this->error("  Failed to add missing peer");
                        $stats['failed']++;
                    } else {
                        $this->info("  Added missing peer");
                        $stats['missing_added']++;
                    }
                    continue;
                }

                $this->warn("  MISMATCH: server={$currentKey} db={$pub}");

                if ($dry) {
                    $this->line("  DRY: would remove {$currentKey} and add {$pub}");
                    continue;
                }

                $repair = $this->executeRemoteCommand($server, $this->buildRepairScript($pub, $ip32));
                if (($repair['status'] ?? 1) !== 0) {
                    $this->error("  Repair failed");
                    $stats['failed']++;
                } else {
                    $this->info("  Repaired");
                    $stats['repaired']++;
                }
            }
        }

        $this->newLine();
        $this->info('Summary');
        $this->line('-------');
        foreach ($stats as $k => $v) {
            $this->line(str_pad($k, 15) . ': ' . $v);
        }

        return self::SUCCESS;
    }

    protected function buildAuditScript(string $ip32): string
    {
        $IP = escapeshellarg($ip32);

        return <<<BASH
set -euo pipefail
IFACE="wg0"
IP32={$IP}

if ! command -v wg >/dev/null 2>&1; then
  echo "NO_WG"
  exit 2
fi

if ! wg show "\$IFACE" >/dev/null 2>&1; then
  echo "NO_IFACE"
  exit 3
fi

CURRENT_KEY=\$(wg show "\$IFACE" allowed-ips | awk -v ip="\$IP32" '\$2 == ip {print \$1}')

if [ -n "\$CURRENT_KEY" ]; then
  echo "CURRENT_KEY=\$CURRENT_KEY"
else
  echo "CURRENT_KEY=NONE"
fi
BASH;
    }

    protected function buildRepairScript(string $publicKey, string $ip32): string
    {
        $PUB = escapeshellarg($publicKey);
        $IP = escapeshellarg($ip32);

        return <<<BASH
set -euo pipefail
IFACE="wg0"
PUB={$PUB}
IP32={$IP}

CURRENT_KEY=\$(wg show "\$IFACE" allowed-ips | awk -v ip="\$IP32" '\$2 == ip {print \$1}')

if [ -n "\$CURRENT_KEY" ] && [ "\$CURRENT_KEY" != "\$PUB" ]; then
  wg set "\$IFACE" peer "\$CURRENT_KEY" remove
fi

wg set "\$IFACE" peer "\$PUB" allowed-ips "\$IP32" persistent-keepalive 25
wg-quick save "\$IFACE"

FINAL_KEY=\$(wg show "\$IFACE" allowed-ips | awk -v ip="\$IP32" '\$2 == ip {print \$1}')
echo "FINAL_KEY=\$FINAL_KEY"
BASH;
    }
}