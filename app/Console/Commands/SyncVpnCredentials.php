<?php

namespace App\Console\Commands;

use App\Jobs\SyncVpnCredentials as SyncVpnCredentialsJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncVpnCredentials extends Command
{
    protected $signature = 'vpn:sync {--sync : Run synchronously instead of queuing}';
    protected $description = 'Sync VPN credentials to the VPN server';

    public function handle(): int
    {
        $this->info('🔄 Syncing VPN credentials...');

        try {
            if ($this->option('sync')) {
                // Run synchronously for testing/debugging
                $job = new SyncVpnCredentialsJob();
                $job->handle();
                $this->info('✅ VPN credentials synced synchronously');
            } else {
                // Queue the job for background processing
                SyncVpnCredentialsJob::dispatch();
                $this->info('✅ VPN credentials sync job queued');
            }

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error('❌ Failed to sync VPN credentials: ' . $e->getMessage());
            Log::error('VPN credentials sync command failed: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

}
