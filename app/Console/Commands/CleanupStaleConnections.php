<?php

namespace App\Console\Commands;

use App\Models\VpnConnection;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CleanupStaleConnections extends Command
{
    protected $signature = 'vpn:cleanup-stale-connections';

    protected $description = 'Mark stale OpenVPN sessions as disconnected (WireGuard excluded)';

    public function handle(): int
    {
        $now = now();

        /*
        |--------------------------------------------------------------------------
        | Find stale active sessions — OPENVPN only
        |--------------------------------------------------------------------------
        |
        | WireGuard has no true disconnect event.  Its session state is managed
        | exclusively by WireGuardEventController, which infers "offline" from
        | last_seen_at freshness on every push from the WireGuard agent.
        |
        | This command must NEVER mark WIREGUARD rows offline — doing so would
        | race against WireGuardEventController and corrupt session history.
        |
        */

        $staleConnections = VpnConnection::query()
            ->where('protocol', 'OPENVPN')
            ->stale($now)
            ->get();

        if ($staleConnections->isEmpty()) {

            $this->info('✅ No stale OpenVPN sessions found');

            return self::SUCCESS;
        }

        $count = 0;

        foreach ($staleConnections as $connection) {

            /*
            |--------------------------------------------------------------------------
            | Already offline
            |--------------------------------------------------------------------------
            */

            if (! $connection->is_active) {
                continue;
            }

            $connection->is_active = false;

            /*
            |--------------------------------------------------------------------------
            | Preserve original disconnect timestamp
            |--------------------------------------------------------------------------
            */

            if (! $connection->disconnected_at) {

                $connection->disconnected_at = $now;
            }

            $connection->save();

            $count++;

            Log::info('OpenVPN session marked stale/offline', [
                'connection_id' => $connection->id,
                'vpn_user_id'   => $connection->vpn_user_id,
                'server_id'     => $connection->vpn_server_id,
                'protocol'      => $connection->protocol,
                'last_seen_at'  => $connection->last_seen_at,
            ]);
        }

        $this->info(
            "🧹 Cleaned {$count} stale OpenVPN sessions"
        );

        return self::SUCCESS;
    }
}