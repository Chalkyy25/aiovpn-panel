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
                            {--server= : Server ID or name}
                            {--only-active=1 : 1=only active (default), 0=include all}
                            {--dry : Show what would run; don\'t dispatch}
                            {--queue=wg : Queue name (default: wg)}
                            {--sync : Run jobs inline (dispatchSync)}
                            {--force : Force update peers (passed to job context)}';

    protected $description = 'Sync WireGuard peers across all or specific servers.';

    public function handle(): int
    {
        $dry        = (bool) $this->option('dry');
        $queue      = (string) ($this->option('queue') ?: 'wg');
        $useSync    = (bool) $this->option('sync');
        $force      = (bool) $this->option('force');
        $onlyActive = (int) $this->option('only-active') === 1;

        // Optional server limiter
        $server = null;
        if ($serverOpt = $this->option('server')) {
            $server = VpnServer::query()
                ->when(is_numeric($serverOpt), fn($q) => $q->where('id', $serverOpt))
                ->when(!is_numeric($serverOpt), fn($q) => $q->where('name', $serverOpt))
                ->first();

            if (!$server) {
                $this->error("Server not found: {$serverOpt}");
                return self::FAILURE;
            }
        }

        // Build query
        $usersQuery = VpnUser::query()
            ->select(['id', 'username', 'wireguard_public_key', 'wireguard_address'])
            ->when($onlyActive, fn($q) => $q->where('is_active', true));

        if (!$usersQuery->exists()) {
            $this->warn('No matching users.');
            return self::SUCCESS;
        }

        $totalUsers   = (int) $usersQuery->count();
        $totalServers = $server ? 1 : VpnServer::count();

        $this->info("🔧 Starting WG sync for {$totalUsers} users across {$totalServers} server(s)...");

        $bar = $this->output->createProgressBar($totalUsers);
        $bar->start();

        $queued = 0;

        $usersQuery->orderBy('id')->chunkById(200, function ($users) use (&$queued, $server, $dry, $queue, $useSync, $force, $bar) {
            foreach ($users as $u) {
                $bar->advance();

                if (blank($u->wireguard_public_key) || blank($u->wireguard_address)) {
                    continue;
                }

                if (!$dry) {
                    $this->dispatchPeerJob($u->id, $server?->id, $queue, $useSync, $force);
                    $queued++;
                }
            }
        });

        $bar->finish();
        $this->newLine(2);

        if ($dry) {
            $this->info('Dry-run complete.');
            return self::SUCCESS;
        }

        // ✅ Final clean summary
        $summary = "✅ [WG] Added {$queued} user" . ($queued !== 1 ? 's' : '') . " across {$totalServers}/{$totalServers} servers.";
        $this->info($summary);
        Log::info($summary);

        return self::SUCCESS;
    }

    protected function dispatchPeerJob(int $userId, ?int $serverId, string $queue, bool $sync, bool $force): void
    {
        $user = VpnUser::select(['id', 'username', 'wireguard_public_key', 'wireguard_address'])
            ->with($serverId ? [] : ['vpnServers:id,name'])
            ->findOrFail($userId);

        $server = $serverId
            ? VpnServer::select(['id', 'name', 'ip_address', 'wg_public_key', 'wg_port', 'wg_endpoint_host'])->findOrFail($serverId)
            : null;

        $job = (new AddWireGuardPeer($user, $server))->onQueue($queue);

        if (method_exists($job, 'setForce')) {
            $job->setForce($force);
        }

        $sync ? dispatch_sync($job) : dispatch($job);
    }
}