<?php

namespace App\Console\Commands;

use App\Jobs\UpdateVpnConnectionStatus;
use App\Models\VpnServer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class UpdateVpnStatus extends Command
{
    protected $signature = 'vpn:update-status
                            {--server= : Run only for a single server name or ID}
                            {--sync : Run synchronously instead of queuing}
                            {--loop : Keep running in a loop (for Supervisor)}
                            {--interval=30 : Seconds between runs when using --loop (min 5)}';

    protected $description = 'Update VPN connection status by parsing OpenVPN status logs from servers';

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
        $serverOpt = $this->option('server');

        $this->info('🔄 Updating VPN connection status' . ($serverOpt ? " for {$serverOpt}" : ' (fleet)') . '...');

        try {
            // prevent overlap with a short lock
            $lock = Cache::lock('vpn-update-status-lock', $interval - 1);

            if ($lock->get()) {
                try {
                    if ($this->option('sync')) {
                        if ($serverOpt) {
                            $server = VpnServer::where('id', $serverOpt)
                                ->orWhere('name', $serverOpt)
                                ->first();

                            if (! $server) {
                                $this->error("❌ Server '{$serverOpt}' not found.");
                                return;
                            }

                            // Run job logic only for this server
                            (new UpdateVpnConnectionStatus())->syncOneServer($server);
                            $this->info("✅ Status updated (sync, {$server->name})");
                        } else {
                            (new UpdateVpnConnectionStatus())->handle();
                            $this->info('✅ VPN status updated (sync, fleet)');
                        }
                    } else {
                        if ($serverOpt) {
                            UpdateVpnConnectionStatus::dispatch()->onQueue('default')->delay(0)->chain([
                                // you could make a single-server variant here if needed
                            ]);
                            $this->info("✅ Queued job (but note: job runs fleet-wide unless we split)");
                        } else {
                            UpdateVpnConnectionStatus::dispatch();
                            $this->info('✅ VPN status update job queued (fleet)');
                        }
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