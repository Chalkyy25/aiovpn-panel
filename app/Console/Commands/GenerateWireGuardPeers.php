<?php

namespace App\Console\Commands;

use App\Jobs\AddWireGuardPeer;
use App\Models\VpnServer;
use App\Models\VpnUser;
use Illuminate\Bus\Batch;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class GenerateWireGuardPeers extends Command
{
    protected $signature = 'wg:generate 
                            {--server= : Only generate for a specific server ID}
                            {--user=   : Only generate for a specific user ID}
                            {--dry     : Show what would be queued, but don\'t enqueue}';

    protected $description = 'Generate WireGuard peers for existing VPN users';

    public function handle(): int
    {
        // single-run guard (5-minute lock window)
        $lock = Cache::lock('cmd:wg:generate', 300);

        if (! $lock->get()) {
            $this->warn('Another wg:generate is running. Exiting.');
            return self::FAILURE;
        }

        try {
            $serverId = $this->option('server');
            $userId   = $this->option('user');
            $dryRun   = (bool) $this->option('dry');

            // servers scope
            $servers = $serverId
                ? VpnServer::query()->whereKey($serverId)->get()
                : VpnServer::all();

            if ($servers->isEmpty()) {
                $this->warn('No servers matched the selection.');
                return self::SUCCESS;
            }

            // users scope (only those that have WG keys/addr)
            $usersQuery = VpnUser::query()
                ->when($userId, fn ($q) => $q->whereKey($userId))
                ->whereNotNull('wireguard_public_key')
                ->where('wireguard_public_key', '!=', '')
                ->whereNotNull('wireguard_address')
                ->where('wireguard_address', '!=', '');

            // Load user->vpnServers relation only when needed
            // Here we’ll iterate by chunks for memory safety
            $totalJobs = 0;
            $preparedJobs = [];

            $this->info(sprintf(
                'Preparing peers%s%s (dry=%s)…',
                $serverId ? " for server #{$serverId}" : '',
                $userId   ? " for user #{$userId}"     : '',
                $dryRun ? 'yes' : 'no'
            ));

            $usersQuery->chunkById(500, function (Collection $users) use ($servers, &$totalJobs, &$preparedJobs) {
                foreach ($users as $user) {
                    // If a user-to-server pivot drives assignment, prefer that.
                    // Otherwise, default to all selected servers.
                    $targetServers = $user->relationLoaded('vpnServers')
                        ? $user->vpnServers
                        : $servers;

                    foreach ($targetServers as $server) {
                        // Skip if peer already exists for (user, server)
                        if (method_exists($user, 'wgPeers') &&
                            $user->wgPeers()->where('server_id', $server->id)->exists()) {
                            $this->line("⏩ {$user->username} already has a peer on {$server->name}");
                            continue;
                        }

                        $this->line("⚙️  Queue {$user->username} on {$server->name}");
                        $preparedJobs[] = (new AddWireGuardPeer($user, $server))->onQueue('wg-peers');
                        $totalJobs++;
                    }
                }
            });

            if ($totalJobs === 0) {
                $this->warn('Nothing to do (no missing peers for the selection).');
                return self::SUCCESS;
            }

            if ($dryRun) {
    $this->info("DRY RUN: {$totalJobs} job(s) would be enqueued.");
    return self::SUCCESS;
}

$this->info("Dispatching {$totalJobs} job(s) individually on 'wg-peers' queue…");

foreach ($preparedJobs as $job) {
    dispatch($job);
}

$this->info('✅ Done. Jobs queued.');
return self::SUCCESS;
        } catch (Throwable $e) {
            Log::error('wg:generate failed: '.$e->getMessage(), ['trace' => $e->getTraceAsString()]);
            $this->error('Command failed: '.$e->getMessage());
            return self::FAILURE;

        } finally {
            optional($lock)->release();
        }
    }
}