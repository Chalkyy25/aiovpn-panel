<?php

namespace App\Console\Commands;

use App\Jobs\AddWireGuardPeer;
use App\Models\VpnServer;
use App\Models\VpnUser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncWireGuardPeers extends Command
{
    protected $signature = 'wg:sync-peers
                            {--user= : Username or user ID to limit}
                            {--server= : Server ID or name to limit}
                            {--all : Include all users (ignores --user)}
                            {--only-active=1 : 1 to include only active users (default), 0 to include all}
                            {--dry : Show what would run; don\'t dispatch}
                            {--queue=wg : Queue to dispatch on (default: wg)}
                            {--sync : Run jobs inline (dispatchSync)}
                            {--force : Hint to downstream to replace/prune on the host (passed to job context)}';

    protected $description = 'Queue AddWireGuardPeer jobs for users across servers (no Redis pipeline).';

    public function handle(): int
    {
        $dry        = (bool) $this->option('dry');
        $queue      = (string) ($this->option('queue') ?: 'wg');
        $useSync    = (bool) $this->option('sync');
        $force      = (bool) $this->option('force');
        $onlyActive = (int) $this->option('only-active') === 1;

        // ----- Resolve optional server limiter -----
        $server = null;
        if ($serverOpt = $this->option('server')) {
            $server = VpnServer::query()
                ->when(is_numeric($serverOpt), fn ($q) => $q->where('id', $serverOpt))
                ->when(!is_numeric($serverOpt), fn ($q) => $q->where('name', $serverOpt))
                ->first();

            if (! $server) {
                $this->error("Server not found: {$serverOpt}");
                return self::FAILURE;
            }
        }

        // ----- Build users query -----
        $usersQuery = VpnUser::query()
            ->select(['vpn_users.id', 'vpn_users.username', 'vpn_users.wireguard_public_key', 'vpn_users.wireguard_address'])
            ->when($onlyActive, fn($q) => $q->where('vpn_users.is_active', true));

        if (!$this->option('all') && ($userOpt = $this->option('user'))) {
            $usersQuery->when(is_numeric($userOpt),
                fn ($q) => $q->where('vpn_users.id', (int) $userOpt),
                fn ($q) => $q->where('vpn_users.username', $userOpt),
            );
        }

        // If no server is specified, we’ll need the user->servers relation in the job.
        // Keep it lightweight.
        if (!$server) {
            $usersQuery->with(['vpnServers:id,name']);
        }

        if (! $usersQuery->exists()) {
            $this->warn('No matching users.');
            return self::SUCCESS;
        }

        $totalQueued  = 0;
        $totalSkipped = 0;

        $this->info(
            sprintf(
                'Starting WG sync%s%s, queue=%s%s%s',
                $server ? " for server={$server->name}" : '',
                $onlyActive ? ' (only-active)' : '',
                $queue,
                $dry ? ' [DRY RUN]' : '',
                $useSync ? ' [SYNC MODE]' : ''
            )
        );

        $bar = $this->output->createProgressBar($usersQuery->count());
        $bar->start();

        $usersQuery->orderBy('vpn_users.id')
            ->chunkById(200, function ($users) use (&$totalQueued, &$totalSkipped, $server, $dry, $queue, $useSync, $force, $bar) {
                foreach ($users as $u) {
                    $bar->advance();

                    // Require WG material
                    if (blank($u->wireguard_public_key) || blank($u->wireguard_address)) {
                        $this->line("  ⏭️ Skipping {$u->username}: missing WG key/address");
                        $totalSkipped++;
                        continue;
                    }

                    // Target: specific server or "user-linked servers" (null server -> job decides)
                    if ($server) {
                        $this->line("  📤 Queue {$u->username} → {$server->name}");
                        if (! $dry) {
                            $this->dispatchPeerJob($u->id, $server->id, $queue, $useSync, $force);
                            $totalQueued++;
                        }
                    } else {
                        $this->line("  📤 Queue {$u->username} → user-linked servers");
                        if (! $dry) {
                            $this->dispatchPeerJob($u->id, null, $queue, $useSync, $force);
                            $totalQueued++;
                        }
                    }
                }
            });

        $bar->finish();
        $this->newLine();

        if ($dry) {
            $this->info('Dry-run complete.');
            return self::SUCCESS;
        }

        $this->info("Dispatched {$totalQueued} job(s). Skipped {$totalSkipped}. Queue: {$queue} " . ($useSync ? '(sync)' : ''));
        Log::info("wg:sync-peers dispatched={$totalQueued} skipped={$totalSkipped} queue={$queue} sync=" . ($useSync ? 1 : 0) . " force=" . ($force ? 1 : 0));

        return self::SUCCESS;
    }

    /**
     * Dispatch a single AddWireGuardPeer job either sync or async, with an explicit queue.
     * If $serverId is null, the job should push the peer to all user-linked servers.
     */
    protected function dispatchPeerJob(int $userId, ?int $serverId, string $queue, bool $sync, bool $force): void
    {
        // Rehydrate minimal models the job needs
        $user = VpnUser::select(['id', 'username', 'wireguard_public_key', 'wireguard_address'])
            ->with($serverId ? [] : ['vpnServers:id,name'])
            ->findOrFail($userId);

        $server = null;
        if ($serverId) {
            $server = VpnServer::select(['id', 'name', 'ip_address', 'wg_public_key', 'wg_port', 'wg_endpoint_host'])
                ->findOrFail($serverId);
        }

        // Pass $force along if your job supports it (you can add a setter or constructor param)
        $job = (new AddWireGuardPeer($user, $server))
            ->onQueue($queue);

        if (method_exists($job, 'setForce')) {
            $job->setForce($force);
        }

        if ($sync) {
            dispatch_sync($job);
        } else {
            dispatch($job);
        }
    }
}