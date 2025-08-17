<?php

namespace App\Console;

use App\Jobs\DisableExpiredVpnUsers;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected function schedule(Schedule $schedule): void
    {
        // ========== HOUSEKEEPING ==========
        // Disable trials & paid lines that have expired
        $schedule->job(DisableExpiredVpnUsers::class)
            ->everyMinute()
            ->onOneServer()
            ->withoutOverlapping()
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/scheduler.log'));

        // ========== VPN FLEET TASKS ==========
        // 1) Update per-server connection status (fast/light)
        $schedule->command('vpn:update-status')
            ->everyMinute()
            ->onOneServer()
            ->withoutOverlapping()
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/scheduler.log'));

        // 2) Sync users to servers (slightly heavier) – staggered
        $schedule->command('vpn:sync-users')
            ->everyTwoMinutes()
            ->onOneServer()
            ->withoutOverlapping()
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/scheduler.log'));

        // 3) Pull active connections (heavier) – further staggered
        $schedule->command('vpn:sync-connections')
            ->everyTwoMinutes()
            ->onOneServer()
            ->withoutOverlapping()
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/scheduler.log'))
            ->delay(now()->addSeconds(30)); // slight offset to avoid clashing with :00/:02 ticks

        // 4) Optional: general sync/maintenance pass (less frequent)
        $schedule->command('vpn:sync')
            ->everyFiveMinutes()
            ->onOneServer()
            ->withoutOverlapping()
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/scheduler.log'));

        // ========== HEALTH / PINGS (optional) ==========
        // if you use an uptime service, uncomment:
        // $schedule->command('queue:monitor database --max=5')
        //     ->everyFiveMinutes()
        //     ->onOneServer()
        //     ->runInBackground()
        //     ->appendOutputTo(storage_path('logs/scheduler.log'));
        //
        // $schedule->pingUrl(env('HEALTHCHECK_SCHEDULE_PING'))
        //     ->everyFiveMinutes();
    }

    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');
        require base_path('routes/console.php');
    }
}