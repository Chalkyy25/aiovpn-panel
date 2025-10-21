<?php

namespace App\Console\Commands;

use App\Jobs\SyncVpnCredentials as SyncVpnCredentialsJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SyncVpnCredentials extends Command
{
    protected $signature = 'vpn:sync
                            {--sync : Run synchronously instead of queuing}
                            {--queue=ovpn : Queue name to dispatch the job on}
                            {--force : Run even if another sync appears to be in progress}';

    protected $description = 'Sync VPN credentials to all VPN servers';

    // Prevent overlapping runs for a short window
    protected string $lockKey = 'vpn:sync-credentials:lock';
    protected int $lockSeconds = 300; // 5 minutes

    public function handle(): int
    {
        $queue = (string) $this->option('queue');
        $sync  = (bool) $this->option('sync');
        $force = (bool) $this->option('force');

        $this->info("🔄 Starting VPN credentials sync " . ($sync ? '(sync mode)' : "(queue={$queue})"));

        // Overlap guard
        $lock = Cache::lock($this->lockKey, $this->lockSeconds);

        if (!$force && !$lock->get()) {
            $this->warn('⚠️ Another sync seems to be running. Use --force to run anyway.');
            Log::warning('[vpn:sync] Skipped: lock in place');
            return self::SUCCESS;
        }

        try {
            if ($sync) {
                // Synchronous (debug)
                $job = new SyncVpnCredentialsJob();
                $job->handle();
                $this->info('✅ VPN credentials synced synchronously');
                Log::info('[vpn:sync] Completed synchronously');
            } else {
                // Queue to chosen queue (defaults to "ovpn")
                SyncVpnCredentialsJob::dispatch()->onQueue($queue);
                $this->info("✅ VPN credentials sync job queued on '{$queue}'");
                Log::info("[vpn:sync] Dispatched job on queue={$queue}");
            }

            return self::SUCCESS;

        } catch (\Throwable $e) {
            $this->error('❌ Failed to sync VPN credentials: ' . $e->getMessage());
            Log::error('VPN credentials sync command failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return self::FAILURE;

        } finally {
            // Release the lock only if we acquired it
            if ($lock->owner()) {
                $lock->release();
            }
        }
    }
}