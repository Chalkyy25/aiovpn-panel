<?php

namespace App\Console;

use App\Jobs\DisableExpiredVpnUsers;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected function schedule(Schedule $schedule): void
    {
        // ===== Housekeeping (queued job) =====
        // NOTE: Do NOT runInBackground() on jobs.
        $schedule->job(DisableExpiredVpnUsers::class)
            ->everyMinute()
            ->onOneServer()
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/scheduler.log'));

        // ===== VPN fleet (artisan commands) =====
        // 1) Fast status — every minute
        $schedule->command('vpn:update-status')
            ->everyMinute()
            ->onOneServer()
            ->withoutOverlapping()
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/scheduler.log'));

        // 2) Sync users — every 2 minutes on ODD minutes (1,3,5,…)
        $schedule->command('vpn:sync-users')
            ->cron('1-59/2 * * * *')
            ->onOneServer()
            ->withoutOverlapping()
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/scheduler.log'));

        // 3) Sync active connections — every 2 minutes on EVEN minutes (0,2,4,…)
        $schedule->command('vpn:sync-connections')
            ->cron('*/2 * * * *')
            ->onOneServer()
            ->withoutOverlapping()
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/scheduler.log'));

        // 4) General maintenance — every 5 minutes
        $schedule->command('vpn:sync')
            ->everyFiveMinutes()
            ->onOneServer()
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