<?php

namespace App\Console;

use App\Jobs\DisableExpiredVpnUsers;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * If you register console commands manually, keep them here.
     * (You can also rely on $this->load(__DIR__.'/Commands') below.)
     */
    protected $commands = [
        \App\Console\Commands\VpnPollServer::class,
    ];

    protected function schedule(Schedule $schedule): void
    {
        // ✅ Housekeeping ONLY (safe, does not touch live connection snapshots)
        $schedule->job(DisableExpiredVpnUsers::class)
            ->everyMinute()
            ->onOneServer()
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/scheduler.log'));

        /*
         * ❌ DISABLED (temporary): These were racing with DeployEventController
         * and causing the dashboard flicker / "_" by rewriting connection state.
         *
         * Re-enable ONE of these later ONLY after refactoring it to:
         * - not broadcast mgmt.update, and/or
         * - not overwrite connected_at / is_connected state
         *
         * $schedule->job(UpdateVpnConnectionStatus::class)->everyFifteenSeconds()...
         * $schedule->command('vpn:sync-connections')->cron
         * $schedule->command('vpn:sync')->everyFiveMinutes()...
         */
    }

    protected function commands(): void
    {
        $this->load(__DIR__ . '/Commands');

        require base_path('routes/console.php');
    }
}