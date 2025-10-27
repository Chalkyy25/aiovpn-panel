<?php

namespace App\Console;

use App\Jobs\DisableExpiredVpnUsers;
use App\Jobs\UpdateVpnConnectionStatus;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{

    protected $commands = [
    \App\Console\Commands\VpnPollServer::class,
];
    protected function schedule(Schedule $schedule): void
    {
        // === Housekeeping (run once only) ===
        $schedule->job(DisableExpiredVpnUsers::class)
            ->everyMinute()
            ->onOneServer() // safe: only 1 copy runs
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/scheduler.log'));

        // === VPN Fleet Sync (run everywhere) ===
        // 1) Fast status — every minute (using job that posts to API)
        $schedule->job(UpdateVpnConnectionStatus::class)
            ->everyMinute()
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/scheduler.log'));

        // 2) Sync Users

        // 3) Sync active connections — every 2 minutes (even minutes)
        $schedule->command('vpn:sync-connections')
            ->cron('*/2 * * * *')
            ->withoutOverlapping()
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/scheduler.log'));

        // 4) General maintenance — every 5 minutes
        $schedule->command('vpn:sync')
            ->everyFiveMinutes()
            ->withoutOverlapping()
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/scheduler.log'));
    }

    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');
        require base_path('routes/console.php');
    }
}