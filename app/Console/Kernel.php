<?php

namespace App\Console;

use App\Jobs\DisableExpiredVpnUsers;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Manually registered commands.
     */
    protected $commands = [

        \App\Console\Commands\VpnPollServer::class,

        \App\Console\Commands\CleanupStaleConnections::class,
    ];

    protected function schedule(Schedule $schedule): void
    {
        /*
        |--------------------------------------------------------------------------
        | VPN user expiry enforcement
        |--------------------------------------------------------------------------
        */

        $schedule->job(DisableExpiredVpnUsers::class)
            ->everyMinute()
            ->onOneServer()
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/scheduler.log'));

        /*
        |--------------------------------------------------------------------------
        | Canonical stale VPN session cleanup
        |--------------------------------------------------------------------------
        |
        | Pollers are responsible for:
        | - updating last_seen_at
        | - updating bandwidth/session metrics
        | - marking connections active when seen
        |
        | Cleanup is responsible for:
        | - expiring stale sessions
        | - marking sessions offline
        | - setting disconnected_at
        |
        | This separation prevents dashboard race conditions,
        | ghost sessions, and inconsistent online counts.
        |
        */

        $schedule->command('vpn:cleanup-stale-connections')
            ->everyMinute()
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/scheduler.log'));

        /*
        |--------------------------------------------------------------------------
        | Disabled legacy sync jobs
        |--------------------------------------------------------------------------
        |
        | These previously raced against realtime pollers and
        | websocket snapshot updates, causing:
        |
        | - dashboard flickering
        | - incorrect connected_at resets
        | - stale online counts
        | - ghost/disappearing sessions
        |
        | Re-enable only after full architectural review.
        |
        */

        /*
        $schedule->job(UpdateVpnConnectionStatus::class)
            ->everyFifteenSeconds();

        $schedule->command('vpn:sync-connections')
            ->cron('* * * * *');

        $schedule->command('vpn:sync')
            ->everyFiveMinutes();
        */
    }

    protected function commands(): void
    {
        $this->load(__DIR__ . '/Commands');

        require base_path('routes/console.php');
    }
}