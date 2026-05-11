<?php

namespace App\Console\Commands;

use App\Models\VpnConnection;
use App\Models\VpnServer;
use App\Models\VpnUser;
use App\Traits\ExecutesRemoteCommands;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * @deprecated  WireGuardPollServer is LEGACY/DEBUG ONLY.
 *
 * WireGuard session state is now managed exclusively by
 * App\Http\Controllers\Api\WireGuardEventController, which receives
 * push events from the WireGuard agent running on each VPN server.
 *
 * This command must NOT be registered in Supervisor or any production
 * scheduler.  It may be run manually for debugging peer connectivity,
 * but it must never be the authoritative writer for vpn_connections rows
 * in a production environment.
 *
 * Production flow:
 *   WireGuard agent  →  POST /api/servers/{id}/wireguard-events
 *                    →  WireGuardEventController::store()
 *                    →  vpn_connections (WIREGUARD rows only)
 */
class WireGuardPollServer extends Command
{
    use ExecutesRemoteCommands;

    /**
     * LEGACY/DEBUG ONLY — do NOT add to Supervisor or production scheduler.
     * See class docblock above.
     */
    protected $signature = 'vpn:poll-wireguard {--interval=10}';

    /**
     * LEGACY/DEBUG ONLY -- WireGuard state is managed by WireGuardEventController.
     */
    protected $description = '[LEGACY/DEBUG ONLY] Poll WireGuard peers -- do NOT run in production';

    public function handle(): int
    {
        $interval = max(1, (int) $this->option('interval'));

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
                        'server_id' => $server->id,
                        'error' => $e->getMessage(),
                    ]);

                    $this->error("❌ {$server->name}: {$e->getMessage()}");
                }
            }

            sleep($interval);
        }
    }

    protected function pollServer(VpnServer $server): void
    {
        $res = $this->executeRemoteCommand(
            $server,
            'bash -lc ' . escapeshellarg('wg show all dump')
        );

        $output = implode("\n", $res['output'] ?? []);

        if (blank($output)) {
            return;
        }

        $now = now();

        foreach (explode("\n", trim($output)) as $line) {
            $parts = preg_split('/\s+/', trim($line));

            /*
            |--------------------------------------------------------------------------
            | Skip interface row
            |--------------------------------------------------------------------------
            |
            | Peer rows have 9 columns in `wg show all dump`.
            |
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

            /*
            |--------------------------------------------------------------------------
            | WireGuard freshness detection
            |--------------------------------------------------------------------------
            |
            | WireGuard has no explicit disconnect event.
            | A peer is treated as live only when the latest handshake is fresh.
            | We do NOT mark stale peers inactive here.
            |
            | Stale expiry belongs to vpn:cleanup-stale-connections.
            |
            */

            $isFresh = false;

            if ($latestHandshake > 0) {
                $secondsAgo = $now->timestamp - $latestHandshake;

                $isFresh = $secondsAgo <= VpnConnection::WIREGUARD_STALE_SECONDS;
            }
            if (! $isFresh) {
                continue;
            }

            $connection = VpnConnection::firstOrNew([
                'vpn_server_id' => $server->id,
                'wg_public_key' => $publicKey,
            ]);

            /*
            |--------------------------------------------------------------------------
            | Preserve first-seen session start
            |--------------------------------------------------------------------------
            */

            if (! $connection->exists || ! $connection->connected_at) {
    $connection->connected_at = $now;
}

$wasStale = ! $connection->last_seen_at
    || $connection->last_seen_at->lt(
        $now->copy()->subSeconds(VpnConnection::WIREGUARD_STALE_SECONDS)
    );

if ($isFresh && $wasStale) {
    $connection->connected_at = $now;
}

            /*
            |--------------------------------------------------------------------------
            | Update peer metadata
            |--------------------------------------------------------------------------
            */

            $connection->vpn_user_id = $vpnUser->id;
            $connection->protocol = 'WIREGUARD';
            $connection->session_key = "wg:{$server->id}:{$publicKey}";

            $connection->client_ip = $endpoint
                ? explode(':', $endpoint)[0]
                : null;

            $connection->virtual_ip = $allowedIps
                ? str_replace('/32', '', $allowedIps)
                : null;

            $connection->endpoint = $endpoint;
            $connection->bytes_in = $rx;
            $connection->bytes_out = $tx;

            /*
            |--------------------------------------------------------------------------
            | Heartbeat handling
            |--------------------------------------------------------------------------
            |
            | last_seen_at is the authoritative WireGuard online signal.
            |
            | We only mark active when fresh.
            | We do NOT mark inactive here, because that creates poller races.
            |
            */

            if ($isFresh) {
                $connection->last_seen_at = $now;

                // Legacy compatibility. Dashboard truth should still use live().
                $connection->is_active = true;

                $connection->disconnected_at = null;
            }

            $connection->save();
        }

        /*
        |--------------------------------------------------------------------------
        | Server heartbeat
        |--------------------------------------------------------------------------
        */

        $server->update([
            'last_sync_at' => $now,
            'is_online' => true,
        ]);

        /*
        |--------------------------------------------------------------------------
        | Legacy write-through cache
        |--------------------------------------------------------------------------
        |
        | Dashboards must NOT read vpn_servers.online_users.
        | They should use VpnConnection::live() / activeConnections().
        |
        */

        $liveCount = VpnConnection::query()
            ->where('vpn_server_id', $server->id)
            ->live($now)
            ->count();

        $server->update([
            'online_users' => $liveCount,
        ]);

        $this->info("📡 {$server->name}: {$liveCount} WG users online");
    }
}