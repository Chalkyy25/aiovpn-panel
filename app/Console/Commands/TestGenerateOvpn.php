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

    $client = \App\Models\Client::findOrFail($userId);

    \App\Jobs\GenerateOvpnFile::dispatch($client);

    $this->info("✅ GenerateOvpnFile job dispatched for client ID {$userId}");
}

}
