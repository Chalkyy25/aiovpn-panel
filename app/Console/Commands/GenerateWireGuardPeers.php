<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\VpnUser;
use App\Models\VpnServer;
use App\Jobs\AddWireGuardPeer;

class GenerateWireGuardPeers extends Command
{
    protected $signature = 'wg:generate {--server=} {--user=}';
    protected $description = 'Generate WireGuard peers for existing VPN users';

    public function handle()
    {
        $serverId = $this->option('server');
        $userId   = $this->option('user');

        $servers = $serverId
            ? VpnServer::where('id', $serverId)->get()
            : VpnServer::all();

        $users = $userId
            ? VpnUser::where('id', $userId)->get()
            : VpnUser::all();

        foreach ($servers as $server) {
            foreach ($users as $user) {
                // Skip if peer already exists
                if ($user->wgPeers()->where('server_id', $server->id)->exists()) {
                    $this->line("⏩ {$user->username} already has a peer on {$server->name}");
                    continue;
                }

                $this->info("⚙️  Creating WireGuard peer for {$user->username} on {$server->name}");
                AddWireGuardPeer::dispatchSync($user, $server);
            }
        }

        $this->info("✅ Done generating WireGuard peers.");
    }
}
