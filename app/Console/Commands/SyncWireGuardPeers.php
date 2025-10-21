<?php

namespace App\Console\Commands;

use App\Jobs\AddWireGuardPeer;
use App\Models\VpnServer;
use App\Models\VpnUser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

class SyncWireGuardPeers extends Command
{
    protected $signature = 'wg:sync-peers
                            {--user= : Username or user ID to limit}
                            {--server= : Server ID or name to limit}
                            {--dry : Show what would run, don\'t execute}';

    protected $description = 'Add/Update WireGuard peers for users across servers';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry');

        // Resolve optional server limiter
        $serverOpt = $this->option('server');
        $server = null;
        if ($serverOpt) {
            $server = VpnServer::query()
                ->when(is_numeric($serverOpt), fn($q) => $q->where('id', $serverOpt))
                ->when(!is_numeric($serverOpt), fn($q) => $q->where('name', $serverOpt))
                ->first();
            if (!$server) {
                $this->error("Server not found: {$serverOpt}");
                return self::FAILURE;
            }
        }

        // Resolve users (optionally limited)
        $userOpt = $this->option('user');
        $users = VpnUser::query()
            ->when($userOpt, function ($q) use ($userOpt) {
                if (is_numeric($userOpt)) { $q->where('id', $userOpt); }
                else { $q->where('username', $userOpt); }
            })
            ->get();

        if ($users->isEmpty()) {
            $this->warn('No matching users.');
            return self::SUCCESS;
        }

        $count = 0;
        $batch = [];

        foreach ($users as $u) {
            // Must have WG materials
            if (!$u->wireguard_public_key || !$u->wireguard_address) {
                $this->warn("Skipping {$u->username}: missing WG key/address");
                continue;
            }

            if ($server) {
                $this->line("Queue: {$u->username} -> {$server->name}");
                if (!$dry) { $batch[] = new AddWireGuardPeer($u, $server); $count++; }
            } else {
                // will load user->vpnServers inside the Job (constructor)
                $this->line("Queue: {$u->username} -> user-linked servers");
                if (!$dry) { $batch[] = new AddWireGuardPeer($u, null); $count++; }
            }
        }

        if ($dry) {
            $this->info('Dry-run complete.');
            return self::SUCCESS;
        }

        if (empty($batch)) {
            $this->warn('Nothing to queue.');
            return self::SUCCESS;
        }

        Bus::batch($batch)->name('wg:sync-peers')->dispatch();
        $this->info("Dispatched {$count} job(s). Watch your queue worker logs.");
        Log::info("wg:sync-peers dispatched {$count} jobs.");

        return self::SUCCESS;
    }
}