<?php

namespace App\Console\Commands;

use App\Models\VpnServer;
use App\Models\VpnUser;
use App\Traits\ExecutesRemoteCommands;
use Illuminate\Console\Command;

class ResyncWireGuardUserPeer extends Command
{
    use ExecutesRemoteCommands;

    protected $signature = 'wg:resync-user-peer
                            {userId : VPN user ID}
                            {--server= : Server ID or server name}
                            {--all-servers : Sync to all linked servers for this user}
                            {--dry : Show script only, do not execute}';

    protected $description = 'Re-sync one VPN user WireGuard peer from DB to server(s), removing any wrong peer using the same IP.';

    public function handle(): int
    {
        $userId = (int) $this->argument('userId');
        $dry = (bool) $this->option('dry');
        $serverOpt = $this->option('server');
        $allServers = (bool) $this->option('all-servers');

        $user = VpnUser::with('vpnServers')->find($userId);

        if (!$user) {
            $this->error("User {$userId} not found.");
            return self::FAILURE;
        }

        if (blank($user->wireguard_public_key) || blank($user->wireguard_address)) {
            $this->error("User {$user->id} is missing wireguard_public_key or wireguard_address.");
            return self::FAILURE;
        }

        if (!blank($user->wireguard_private_key)) {
            $derived = trim(shell_exec("printf '%s' ".escapeshellarg($user->wireguard_private_key)." | wg pubkey"));
            if ($derived !== $user->wireguard_public_key) {
                $this->error("DB keypair mismatch for user {$user->id}. Refusing to continue.");
                $this->line("DB public:      {$user->wireguard_public_key}");
                $this->line("Derived public: {$derived}");
                return self::FAILURE;
            }
        }

        $servers = collect();

        if ($serverOpt) {
            $server = VpnServer::query()
                ->when(
                    is_numeric($serverOpt),
                    fn ($q) => $q->where('id', (int) $serverOpt),
                    fn ($q) => $q->where('name', $serverOpt)
                )
                ->first();

            if (!$server) {
                $this->error("Server not found: {$serverOpt}");
                return self::FAILURE;
            }

            $servers = collect([$server]);
        } elseif ($allServers) {
            $servers = $user->vpnServers ?? collect();
        } else {
            $servers = $user->vpnServers ? collect([$user->vpnServers->first()])->filter() : collect();
        }

        if ($servers->isEmpty()) {
            $this->error("No target servers found for user {$user->id}.");
            return self::FAILURE;
        }

        $publicKey = trim((string) $user->wireguard_public_key);
        $address = trim((string) $user->wireguard_address);
        $ipOnly = preg_replace('/\/\d+$/', '', $address);
        $ip32 = "{$ipOnly}/32";

        $this->info("User: {$user->username} (#{$user->id})");
        $this->line("Public key: {$publicKey}");
        $this->line("Address: {$ip32}");
        $this->newLine();

        foreach ($servers as $server) {
            $this->info("→ Server: {$server->name} ({$server->ip_address})");

            $script = $this->buildScript($publicKey, $ip32);

            if ($dry) {
                $this->line($script);
                $this->newLine();
                continue;
            }

            $res = $this->executeRemoteCommand($server, $script);
            $status = $res['status'] ?? 1;
            $output = trim(implode("\n", (array) ($res['output'] ?? [])));

            if ($status !== 0) {
                $this->error("Failed on {$server->name} (exit {$status})");
                if ($output !== '') {
                    $this->line($output);
                }
                continue;
            }

            $this->info("Synced successfully on {$server->name}");
            if ($output !== '') {
                $this->line($output);
            }

            $this->newLine();
        }

        return self::SUCCESS;
    }

    protected function buildScript(string $publicKey, string $ip32): string
    {
        $PUB = escapeshellarg($publicKey);
        $IP = escapeshellarg($ip32);

        return <<<BASH
set -euo pipefail
IFACE="wg0"
PUB={$PUB}
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

if [ -n "\$CURRENT_KEY" ] && [ "\$CURRENT_KEY" != "\$PUB" ]; then
  wg set "\$IFACE" peer "\$CURRENT_KEY" remove
  echo "REMOVED_OLD=\$CURRENT_KEY"
fi

wg set "\$IFACE" peer "\$PUB" allowed-ips "\$IP32" persistent-keepalive 25
echo "ADDED_NEW=\$PUB"

wg-quick save "\$IFACE"
echo "SAVED=1"

wg show "\$IFACE" allowed-ips | awk -v ip="\$IP32" '\$2 == ip {print "FINAL_KEY="\$1}'
BASH;
    }
}