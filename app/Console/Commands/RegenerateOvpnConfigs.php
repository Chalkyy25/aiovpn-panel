<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\VpnUser;
use App\Jobs\GenerateOvpnFile;

class RegenerateOvpnConfigs extends Command
{
    protected $signature = 'vpn:regen-configs {--stealth : Generate stealth-focused configs}';
    protected $description = 'Regenerate modern stealth-enabled .ovpn config files for all users';

    public function handle()
    {
        $users = VpnUser::with('vpnServers')->get();
        $isStealthMode = $this->option('stealth');

        $this->info('🚀 Starting modern config regeneration...');
        
        if ($isStealthMode) {
            $this->warn('🛡️ Stealth mode enabled - prioritizing TCP 443 configurations');
        }

        foreach ($users as $user) {
            $this->info("📡 Dispatching stealth configs for {$user->username} ({$user->vpnServers->count()} servers)");
            GenerateOvpnFile::dispatch($user);
        }

        $this->info("✅ All modern stealth configs dispatched for {$users->count()} users.");
        $this->info('💡 Configs include: Unified (TCP 443 + UDP fallback), Stealth-only, Traditional UDP, and WireGuard (if available)');
    }
}
