<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Jobs\GenerateOvpnFile;
use App\Models\VpnUser;

class TestGenerateOvpn extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:test-generate-ovpn {userId}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test GenerateOvpnFile job for a given VPN user ID';

    /**
     * Execute the console command.
     */
public function handle()
{
    $userId = $this->argument('userId');
    $vpnUser = VpnUser::find($userId);

    if (!$vpnUser) {
        $this->error("User ID {$userId} not found.");
        return;
    }

    \App\Jobs\GenerateOvpnFile::dispatch($vpnUser);
    $this->info("✅ GenerateOvpnFile job dispatched for user ID {$userId} ({$vpnUser->username}).");
}
}
