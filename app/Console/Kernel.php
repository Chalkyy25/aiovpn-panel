<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Cron jobs removed - VPN operations now run as normal jobs
        // Use the following commands to trigger jobs manually:
        // php artisan vpn:sync (queues SyncVpnCredentials job)
        // php artisan vpn:update-status (queues UpdateVpnConnectionStatus job)
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
