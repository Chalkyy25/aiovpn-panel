<?php

namespace App\Console\Commands;

use App\Models\VpnConnection;
use App\Models\VpnServer;
use App\Models\VpnUser;
use App\Traits\ExecutesRemoteCommands;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class WireGuardPollServer extends Command
{
    use ExecutesRemoteCommands;

    protected $signature = 'vpn:poll-wireguard {--interval=10}';

    protected $description = 'Poll WireGuard peers and update live status';

    public function handle(): int
    {
        $interval = (int) $this->option('interval');

        $this->info("🚀 Starting WireGuard poller ({$interval}s)");

        while (true) {

            $servers = VpnServer::query()
                ->where('deployment_status', 'success')
                ->where(function ($q) {
                    $q->where('protocol', 'wireguard')
                        ->orWhere('supports_wireguard', true);
                })
                ->get();

            foreach ($servers as $server) {

                try {

                    $this->pollServer($server);

                } catch (\Throwable $e) {

                    Log::error("WG poll failed for {$server->name}", [
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            sleep($interval);
        }
    }

    protected function pollServer(VpnServer $server): void

{

    $cmd = 'wg show all dump';

    $res = $this->executeRemoteCommand(

        $server,

        'bash -lc ' . escapeshellarg($cmd)

    );

    $output = implode("\n", $res['output'] ?? []);

    if (blank($output)) {

        return;

    }

    $lines = explode("\n", trim($output));

    foreach ($lines as $line) {

        $parts = preg_split('/\s+/', trim($line));

        /*

        |--------------------------------------------------------------------------

        | Skip interface row

        |--------------------------------------------------------------------------

        */

        if (count($parts) < 9) {

            continue;

        }

        $publicKey = $parts[1] ?? null;

        $endpoint = $parts[3] ?? null;

        $allowedIps = $parts[4] ?? null;

        $latestHandshake = (int) ($parts[5] ?? 0);

        $rx = (int) ($parts[6] ?? 0);

        $tx = (int) ($parts[7] ?? 0);

        if (! $publicKey) {

            continue;

        }

        /*

        |--------------------------------------------------------------------------

        | Find VPN user

        |--------------------------------------------------------------------------

        */

        $vpnUser = VpnUser::query()

            ->where('wireguard_public_key', $publicKey)

            ->first();

        if (! $vpnUser) {

            $this->warn(

                "No VPN user found for {$publicKey}"

            );

            continue;

        }

        /*

        |--------------------------------------------------------------------------

        | Online detection

        |--------------------------------------------------------------------------

        */

        // WireGuard does not send explicit disconnect/connect packets; the only
        // reliable signal is the latest-handshake timestamp from `wg show dump`.
        // A peer is considered "online" when its handshake occurred within the
        // canonical WIREGUARD_STALE_SECONDS window AND it has transferred data.
        $isOnline = false;

        if ($latestHandshake > 0) {

            $secondsAgo = now()->timestamp - $latestHandshake;

            $isOnline =

                $secondsAgo <= VpnConnection::WIREGUARD_STALE_SECONDS &&

                ($rx > 0 || $tx > 0);

        }

        /*

        |--------------------------------------------------------------------------

        | Load existing connection

        |--------------------------------------------------------------------------

        */

        $connection = VpnConnection::firstOrNew([

            'vpn_server_id' => $server->id,

            'wg_public_key' => $publicKey,

        ]);

        /*

        |--------------------------------------------------------------------------

        | Preserve original connection time

        |--------------------------------------------------------------------------

        */

        if (! $connection->exists || ! $connection->connected_at) {

            $connection->connected_at = now();

        }

        /*

        |--------------------------------------------------------------------------

        | Detect reconnects

        |--------------------------------------------------------------------------

        */

        $wasOffline = ! $connection->is_active;

        if ($isOnline && $wasOffline) {
        
            $connection->connected_at = now();
        }

        /*

        |--------------------------------------------------------------------------

        | Update connection

        |--------------------------------------------------------------------------

        */

        $connection->vpn_user_id = $vpnUser->id;

        $connection->protocol = 'WIREGUARD';

        $connection->session_key =

            "wg:{$server->id}:{$publicKey}";

        $connection->client_ip = $endpoint

            ? explode(':', $endpoint)[0]

            : null;

        $connection->virtual_ip = $allowedIps

            ? str_replace('/32', '', $allowedIps)

            : null;

        $connection->endpoint = $endpoint;

        $connection->bytes_in = $rx;

        $connection->bytes_out = $tx;

        $connection->is_active = $isOnline;

        /*

        |--------------------------------------------------------------------------

        | Heartbeat handling

        |--------------------------------------------------------------------------

        */

        if ($isOnline) {

            $connection->last_seen_at = now();

            $connection->disconnected_at = null;

        } else {

            $connection->disconnected_at = now();

        }

        $connection->save();

    }

    /*

    |--------------------------------------------------------------------------

    | Mark stale sessions offline

    |--------------------------------------------------------------------------

    */

    VpnConnection::query()

        ->where('vpn_server_id', $server->id)

        ->where('protocol', 'WIREGUARD')

        ->where(function ($q) {

            $q->whereNull('last_seen_at')

              ->orWhere(

                  'last_seen_at',

                  '<',

                  now()->subSeconds(VpnConnection::WIREGUARD_STALE_SECONDS)

              );

        })

        ->update([

            'is_active' => false,

            'disconnected_at' => now(),

        ]);

    /*

    |--------------------------------------------------------------------------

    | Update server stats

    |--------------------------------------------------------------------------

    */

    $server->update([

        'last_sync_at' => now(),

        'is_online' => true,

    ]);

    // Live count is derived from VpnConnection::live() — no need to cache it on vpn_servers.online_users.
    $liveCount = VpnConnection::query()
        ->where('vpn_server_id', $server->id)
        ->live()
        ->count();

    $this->info(

        "📡 {$server->name}: {$liveCount} WG users online"

    );

}
}