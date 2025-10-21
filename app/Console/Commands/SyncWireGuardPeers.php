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
                            {--dry : Show what would run, don\'t execute}
                            {--queue=wg : Queue name to dispatch on (default: wg)}
                            {--sync : Run jobs inline (dispatchSync) for small sets}';

    protected $description = 'Add/Update WireGuard peers for users across servers (no Redis batch/pipeline).';

    public function handle(): int
    {
        $dry     = (bool) $this->option('dry');
        $queue   = (string) $this->option('queue') ?: 'wg';
        $useSync = (bool) $this->option('sync');

        // ----- Resolve optional server limiter -----
        $serverOpt = $this->option('server');
        $server = null;
        if ($serverOpt) {
            $server = VpnServer::query()
                ->when(is_numeric($serverOpt), fn ($q) => $q->where('id', $serverOpt))
                ->when(!is_numeric($serverOpt), fn ($q) => $q->where('name', $serverOpt))
                ->first();

            if (! $server) {
                $this->error("Server not found: {$serverOpt}");
                return self::FAILURE;
            }
        }

        // ----- Resolve users (optionally limited) -----
        $userOpt = $this->option('user');

        $usersQuery = VpnUser::query()
            ->when($userOpt, function ($q) use ($userOpt) {
                if (is_numeric($userOpt)) {
                    $q->where('id', $userOpt);
                } else {
                    $q->where('username', $userOpt);
                }
            })
            // we only need columns used here to keep memory light
            ->select(['id', 'username', 'wireguard_public_key', 'wireguard_address']);

        if (! $usersQuery->exists()) {
            $this->warn('No matching users.');
            return self::SUCCESS;
        }

        $totalQueued = 0;
        $totalSkipped = 0;

        // Stream in chunks to avoid loading all users at once.
        $usersQuery->orderBy('id')->chunkById(200, function ($users) use (&$totalQueued, &$totalSkipped, $server, $dry, $queue, $useSync) {
            foreach ($users as $u) {
                // Must have WG materials
                if (blank($u->wireguard_public_key) || blank($u->wireguard_address)) {
                    $this->warn("Skipping {$u->username}: missing WG key/address");
                    $totalSkipped++;
                    continue;
                }

                // Decide target
                if ($server) {
                    $this->line("Queue: {$u->username} -> {$server->name}");
                    if (! $dry) {
                        $this->dispatchJob($u->id, $server->id, $queue, $useSync);
                        $totalQueued++;
                    }
                } else {
                    $this->line("Queue: {$u->username} -> user-linked servers");
                    if (! $dry) {
                        $this->dispatchJob($u->id, null, $queue, $useSync);
                        $totalQueued++;
                    }
                }
            }
        });

        if ($dry) {
            $this->info('Dry-run complete.');
            return self::SUCCESS;
        }

        $this->info("Dispatched {$totalQueued} job(s). Skipped {$totalSkipped} user(s). Queue: {$queue} " . ($useSync ? '(sync)' : ''));
        Log::info("wg:sync-peers dispatched={$totalQueued} skipped={$totalSkipped} queue={$queue} sync=" . ($useSync ? 1 : 0));

        return self::SUCCESS;
    }

    /**
     * Dispatch a single job either sync or async, with an explicit queue.
     */
    protected function dispatchJob(int $userId, ?int $serverId, string $queue, bool $sync): void
    {
        // Re-hydrate minimal models for the Job constructor
        $user   = VpnUser::select(['id', 'username', 'wireguard_public_key', 'wireguard_address'])
                    ->with('vpnServers:id,name')  // lightweight for the job when serverId is null
                    ->findOrFail($userId);

        $server = null;
        if ($serverId) {
            $server = VpnServer::select(['id', 'name', 'ip_address'])->findOrFail($serverId);
        }

        $job = (new AddWireGuardPeer($user, $server))->onQueue($queue);

        if ($sync) {
            dispatch_sync($job); // runs inline; good for troubleshooting small sets
        } else {
            dispatch($job);      // normal async via Horizon
        }
    }
}