<?php

namespace App\Console\Commands;

use App\Jobs\UpdateVpnConnectionStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class UpdateVpnStatus extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'vpn:update-status {--sync : Run synchronously instead of queuing}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update VPN connection status by parsing OpenVPN status logs from all active servers';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('🔄 Updating VPN connection status...');

        try {
            if ($this->option('sync')) {
                // Run synchronously for testing/debugging
                $job = new UpdateVpnConnectionStatus();
                $job->handle();
                $this->info('✅ VPN status updated synchronously');
            } else {
                // Queue the job for background processing
                UpdateVpnConnectionStatus::dispatch();
                $this->info('✅ VPN status update job queued');
            }

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error('❌ Failed to update VPN status: ' . $e->getMessage());
            Log::error('VPN status update command failed: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
