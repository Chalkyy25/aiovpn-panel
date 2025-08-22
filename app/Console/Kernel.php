<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

use App\Jobs\UpdateVpnConnectionStatus;
use App\Jobs\DisableExpiredVpnUsers;

class Kernel extends ConsoleKernel
{
    protected function schedule(Schedule $schedule): void
    {
        // --- Live snapshot -> DB + Reverb (hybrid) ---
        // Queue name is optional; use one Horizon is watching (e.g. "realtime").
        $schedule->job((new UpdateVpnConnectionStatus)->onQueue('realtime'))
            ->everyMinute()
            ->onOneServer()
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/scheduler.log'));

        // --- Housekeeping: disable/lock expired users ---
        $schedule->job((new DisableExpiredVpnUsers)->onQueue('default'))
            ->everyFiveMinutes()
            ->onOneServer()
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/scheduler.log'));

        // Optional: Horizon metrics snapshots (nice to have)
        // $schedule->command('horizon:snapshot')
        //     ->everyFiveMinutes()
        //     ->appendOutputTo(storage_path('logs/scheduler.log'));

        // ⛔️ Legacy command-based syncs replaced by the job above:
        // $schedule->command('vpn:update-status')...
        // $schedule->command('vpn:sync-users')...
        // $schedule->command('vpn:sync-connections')...
        // $schedule->command('vpn:sync')...
    }

    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');
        require base_path('routes/console.php');
    }
}