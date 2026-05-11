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

            if (count($parts) < 8) {
                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | wg show dump format
            |--------------------------------------------------------------------------
            |
            | 0 interface
            | 1 public_key
            | 2 preshared_key
            | 3 endpoint
            | 4 allowed_ips
            | 5 latest_handshake
            | 6 rx_bytes
            | 7 tx_bytes
            | 8 persistent_keepalive
            |
            */

            $publicKey = $parts[1] ?? null;

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
                    'vpn_user_id' => $vpnUser->id,
                    'protocol' => 'WIREGUARD',
                    'session_key' => $publicKey,
                ],
                [
                    'wg_public_key' => $publicKey,
                    'bytes_in' => $rx,
                    'bytes_out' => $tx,
                    'is_active' => $isOnline,
                    'last_seen_at' => $isOnline ? now() : null,
                    'connected_at' => $isOnline ? now() : null,
                    'disconnected_at' => $isOnline ? null : now(),
                ]
            );
        }

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