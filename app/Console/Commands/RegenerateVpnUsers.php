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
    protected $signature = 'vpn:regenerate-users {count=10} {--server=*} {--preserve=*}';
    protected $description = 'Regenerate VPN users and assign them to servers. Use --preserve option to keep specific usernames.';

    public function handle(): int
    {
        $count = $this->argument('count');
        $serverIds = $this->option('server');
        $preserveUsernames = $this->option('preserve');

        // Get servers
        $servers = empty($serverIds)
            ? VpnServer::all()
            : VpnServer::whereIn('id', $serverIds)->get();

        if ($servers->isEmpty()) {
            $this->error('❌ No VPN servers found!');
            return 1;
        }

        $this->info("🔄 Regenerating VPN users...");

        // First, delete all relationships except preserved users
        if (!empty($preserveUsernames)) {
            $this->info("🛡️ Preserving users: " . implode(', ', $preserveUsernames));
            $preservedUsers = VpnUser::whereIn('username', $preserveUsernames)->get();

            // Delete relationships only for non-preserved users
            DB::table('vpn_user_server')
                ->whereNotIn('user_id', $preservedUsers->pluck('id'))
                ->delete();

            // Delete non-preserved users
            VpnUser::whereNotIn('username', $preserveUsernames)->delete();
        } else {
            DB::table('vpn_user_server')->truncate();
            VpnUser::query()->delete();
        }

        $this->info("🗑️ Cleared existing non-preserved VPN users");

        // Calculate how many new users to create
        $existingCount = VpnUser::count();
        $toCreate = $count - $existingCount;

        if ($toCreate <= 0) {
            $this->info("ℹ️ No new users needed, already have $existingCount users");
            return 0;
        }

        $bar = $this->output->createProgressBar($toCreate);

        // Create new users
        for ($i = 0; $i < $toCreate; $i++) {
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

        $this->info("\n🎉 Done! Now have " . VpnUser::count() . " total users assigned to " . $servers->count() . " servers.");

        return 0;
    }
}
