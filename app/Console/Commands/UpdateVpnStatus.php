<?php

namespace App\Console\Commands;

use App\Jobs\UpdateVpnConnectionStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class UpdateVpnStatus extends Command
{
    protected $signature = 'vpn:update-status
                            {--sync : Run synchronously instead of queuing}
                            {--loop : Keep running in a loop (for Supervisor)}
                            {--interval=30 : Seconds between runs when using --loop (min 5)}';

    protected $description = 'Update VPN connection status by parsing OpenVPN status logs from all active servers';

    public function handle(): int
    {
        $interval = max(5, (int)$this->option('interval'));
        $loop     = (bool)$this->option('loop');

        if ($loop) {
            $this->info("🔁 VPN monitor loop started (every {$interval}s). Ctrl+C to stop.");
            while (true) {
                $this->tick($interval);
                sleep($interval);
            }
        } else {
            $this->tick($interval);
        }

        return Command::SUCCESS;
    }

    private function tick(int $interval): void
    {
        $this->info('🔄 Updating VPN connection status...');
        try {
            // prevent overlap with a short lock
            $lock = Cache::lock('vpn-update-status-lock', $interval - 1);

            if ($lock->get()) {
                try {
                    if ($this->option('sync')) {
                        (new UpdateVpnConnectionStatus())->handle();
                        $this->info('✅ VPN status updated (sync)');
                    } else {
                        UpdateVpnConnectionStatus::dispatch();
                        $this->info('✅ VPN status update job queued');
                    }
                } finally {
                    optional($lock)->release();
                }
            } else {
                $this->info('⏭️ Skipping run (previous still in progress)');
            }
        } catch (\Throwable $e) {
            $this->error('❌ Failed to update VPN status: '.$e->getMessage());
            Log::error('VPN status update command failed: '.$e->getMessage(), ['trace' => $e->getTraceAsString()]);
        }
    }
}