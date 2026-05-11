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

    protected $signature = 'vpn:poll-wireguard
                            {--interval=10}';

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
        $cmd = "wg show all dump";

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
    | Skip interface line
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

    $vpnUser = VpnUser::query()
        ->where('wireguard_public_key', $publicKey)
        ->first();

    if (! $vpnUser) {

        $this->warn("No VPN user found for {$publicKey}");

        continue;
    }

    $isOnline = false;

    if ($latestHandshake > 0) {

        $secondsAgo = now()->timestamp - $latestHandshake;

        $isOnline = $secondsAgo <= 180;
    }

    VpnConnection::updateOrCreate(
    [
        'vpn_server_id' => $server->id,
        'wg_public_key' => $publicKey,
    ],
    [
        'vpn_user_id' => $vpnUser->id,

        'protocol' => 'WIREGUARD',

        'session_key' => $publicKey,

        'endpoint' => $endpoint,

        'virtual_ip' => $allowedIps,

        'bytes_in' => $rx,

        'bytes_out' => $tx,

        'is_active' => $isOnline,

        'last_seen_at' => $isOnline
            ? now()
            : null,

        'connected_at' => $isOnline
            ? now()
            : null,

        'disconnected_at' => $isOnline
            ? null
            : now(),
    ]
);

        $onlineUsers = VpnConnection::query()
            ->where('vpn_server_id', $server->id)
            ->where('protocol', 'WIREGUARD')
            ->where('is_active', true)
            ->count();

        $server->update([
            'online_users' => $onlineUsers,
            'last_sync_at' => now(),
        ]);

        $this->info("📡 {$server->name}: {$onlineUsers} WG users online");
    }
}