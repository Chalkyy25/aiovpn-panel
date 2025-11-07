<?php

namespace App\Console\Commands;

use App\Jobs\AddWireGuardPeer;
use App\Models\VpnServer;
use App\Models\VpnUser;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
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
        $lock = Cache::lock('cmd:wg:generate', 300);

        if (! $lock->get()) {
            $this->warn('Another wg:generate is running. Exiting.');
            return self::FAILURE;
        }

        try {
            $serverId = $this->option('server');
            $userId   = $this->option('user');
            $dryRun   = (bool) $this->option('dry');

            // If --server is given, ensure it exists (mainly for messaging)
            $servers = $serverId
                ? VpnServer::query()->whereKey($serverId)->get()
                : VpnServer::all();

            if ($serverId && $servers->isEmpty()) {
                $this->warn("No server found with ID {$serverId}.");
                return self::SUCCESS;
            }

            // Users with valid WG material
            $usersQuery = VpnUser::query()
                ->when($userId, fn ($q) => $q->whereKey($userId))
                ->whereNotNull('wireguard_public_key')
                ->where('wireguard_public_key', '!=', '')
                ->whereNotNull('wireguard_address')
                ->where('wireguard_address', '!=', '');

            $this->info(sprintf(
                'Preparing peers%s%s (dry=%s)…',
                $serverId ? " for server #{$serverId}" : '',
                $userId   ? " for user #{$userId}"     : '',
                $dryRun ? 'yes' : 'no'
            ));

            $jobs = [];
            $totalJobs = 0;

            $usersQuery->chunkById(500, function (Collection $users) use ($serverId, $servers, &$jobs, &$totalJobs) {
                foreach ($users as $user) {
                    // If a specific server is targeted, queue one job: (user, that server)
                    if ($serverId) {
                        $server = $servers->first();
                        if (! $server) {
                            continue;
                        }

                        $this->line("⚙️  Queue {$user->username} on {$server->name}");
                        $jobs[] = (new AddWireGuardPeer($user, $server))->onQueue('wg-peers');
                        $totalJobs++;
                        continue;
                    }

                    // Default: one job per user to handle all linked servers (Option A)
                    $this->line("⚙️  Queue {$user->username} on all linked servers");
                    $jobs[] = (new AddWireGuardPeer($user))->onQueue('wg-peers');
                    $totalJobs++;
                }
            });

            if ($totalJobs === 0) {
                $this->warn('Nothing to do (no eligible users found for the selection).');
                return self::SUCCESS;
            }

            if ($dryRun) {
                $this->info("DRY RUN: {$totalJobs} job(s) would be enqueued.");
                return self::SUCCESS;
            }

            $this->info("Dispatching {$totalJobs} job(s) on 'wg-peers' queue…");

            foreach ($jobs as $job) {
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