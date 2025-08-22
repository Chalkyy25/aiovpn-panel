<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\VpnUser;
use App\Jobs\GenerateOvpnFile;

class RegenerateOvpnConfigs extends Command
{
    protected $signature = 'vpn:regen-configs';
    protected $description = 'Regenerate .ovpn config files for all users';

    public function handle()
    {
        $users = VpnUser::with('vpnServers')->get();

        foreach ($users as $user) {
            $this->info("Dispatching config for {$user->username}");
            GenerateOvpnFile::dispatch($user);
        }

        $this->info('✅ All configs dispatched.');
    }
}
