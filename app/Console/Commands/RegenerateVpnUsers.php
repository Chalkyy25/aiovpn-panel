<?php

namespace App\Console\Commands;

use App\Jobs\SyncOpenVPNCredentials;
use App\Models\VpnServer;
use App\Models\VpnUser;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class RegenerateVpnUsers extends Command
{
    protected $signature = 'vpn:regenerate-users {count=10} {--server=*}';
    protected $description = 'Regenerate VPN users and assign them to servers';

    public function handle(): int
    {
        $count = $this->argument('count');
        $serverIds = $this->option('server');

        // Get servers
        $servers = empty($serverIds)
            ? VpnServer::all()
            : VpnServer::whereIn('id', $serverIds)->get();

        if ($servers->isEmpty()) {
            $this->error('❌ No VPN servers found!');
            return 1;
        }

        $this->info("🔄 Regenerating $count VPN users...");
        $bar = $this->output->createProgressBar($count);

        // First, delete all relationships
        DB::table('vpn_user_server')->truncate();

        // Then delete users
        VpnUser::query()->delete(); // Using delete() instead of truncate()
        $this->info("\n🗑️ Cleared existing VPN users");

        // Create new users
        for ($i = 0; $i < $count; $i++) {
            $username = 'vpn-' . Str::random(6);
            $password = Str::random(12);

            $user = VpnUser::create([
                'username' => $username,
                'plain_password' => $password,
                'password' => bcrypt($password),
                'device_name' => "Test Device $i",
                'is_active' => true,
                'max_connections' => 1
            ]);

            // Attach all servers or specified servers
            $user->vpnServers()->attach($servers->pluck('id'));

            $bar->advance();
        }

        $bar->finish();

        // Sync OpenVPN credentials for all servers
        $this->info("\n\n🔄 Syncing OpenVPN credentials...");
        foreach ($servers as $server) {
            SyncOpenVPNCredentials::dispatch($server);
            $this->info("✅ Queued sync for $server->name");
        }

        $this->info("\n🎉 Done! Created $count users and assigned them to " . $servers->count() . " servers.");

        // Show example usage
        $this->info("\nExample usage:");
        $user = VpnUser::first();
        $this->info("Username: $user->username");
        $this->info("Password: $user->plain_password");

        return 0;
    }
}
