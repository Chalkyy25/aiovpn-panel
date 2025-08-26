<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Jobs\UpdateVpnConnectionStatus;
use Illuminate\Support\Facades\Log;

class VpnPollServer extends Command
{
    protected $signature = 'vpn:poll-server {serverId} {--interval=5}';
    protected $description = 'Continuously poll a single VPN server mgmt and push snapshots';

    public function handle(): int
    {
        $serverId = (int) $this->argument('serverId');
        $interval = (int) $this->option('interval');

        $this->info("🔄 Starting poller for server {$serverId} every {$interval}s");

        while (true) {
            try {
                dispatch_sync(new UpdateVpnConnectionStatus($serverId));
            } catch (\Throwable $e) {
                Log::error("Poller {$serverId} failed: ".$e->getMessage());
            }

            sleep($interval);
        }

        return 0;
    }
}