<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected function schedule(Schedule $schedule): void
    {
        // Disable expired trials & paid lines
        $schedule->job(new \App\Jobs\DisableExpiredVpnUsers)
            ->everyMinute()
            ->onOneServer()          // safe when multiple schedulers could exist
            ->withoutOverlapping();  // don’t run a second copy if the first is still going
    }

    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');
        require base_path('routes/console.php');
    }
}