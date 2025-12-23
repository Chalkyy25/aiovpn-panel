<?php

namespace App\Jobs;

use App\Models\VpnServer;
use App\Traits\ExecutesRemoteCommands;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class UpdateWireGuardConnectionStatus implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, ExecutesRemoteCommands;

    /**
     * A peer is considered ONLINE if latest_handshake is within this window.
     * This must match your controller’s STALE_SECONDS (3 minutes).
     */
    private const ONLINE_HANDSHAKE_SECONDS = 180;

    protected ?int $serverId;

    public function __construct(?int $serverId = null)
    {
        $this->serverId = $serverId;
    }

    public function handle(): void
    {
        Log::channel('vpn')->info(
            '🔄 WireGuard sync: updating connection status ' .
            ($this->serverId ? "(server {$this->serverId})" : '(fleet)')
        );

        $servers = VpnServer::query()
            ->whereIn('deployment_status', ['succeeded', 'deployed'])
            ->where(function ($q) {
                // be flexible if you store protocol in different forms
                $q->where('protocol', 'wireguard')
                  ->orWhere('protocol', 'WIREGUARD')
                  ->orWhere('protocol', 'wg')
                  ->orWhere('protocol', 'WG');
            })
            ->when($this->serverId, fn ($q) => $q->where('id', $this->serverId))
            ->get();

        if ($servers->isEmpty()) {
            Log::channel('vpn')->warning(
                $this->serverId
                    ? "⚠️ No WireGuard server found with ID {$this->serverId}"
                    : "⚠️ No WireGuard servers found."
            );
            return;
        }

        foreach ($servers as $server) {
            $this->syncOneWireGuardServer($server);
        }

        Log::channel('vpn')->info('✅ WireGuard sync completed');
    }

    protected function syncOneWireGuardServer(VpnServer $server): void
    {
        try {
            $peers = $this->fetchOnlineWireGuardPeers($server);

            Log::channel('vpn')->debug(
                "[wg-sync] {$server->name} online_peers=" . count($peers),
                ['sample' => array_slice(array_column($peers, 'public_key'), 0, 5)]
            );

            // IMPORTANT:
            // Send empty peers array too (so controller can mark stale/offline)
            $this->pushSnapshot($server->id, now(), $peers);
        } catch (\Throwable $e) {
            Log::channel('vpn')->error("❌ {$server->name}: WireGuard sync failed – {$e->getMessage()}");
        }
    }

    /**
     * Returns ONLY "online" peers.
     * wg show dump gives latest_handshake as UNIX epoch seconds (0 means never).
     */
    protected function fetchOnlineWireGuardPeers(VpnServer $server): array
    {
        // SSH smoke test
        $sshTest = $this->executeRemoteCommand($server, 'bash -lc ' . escapeshellarg('echo "SSH_TEST_OK"'));
        if (($sshTest['status'] ?? 1) !== 0) {
            Log::channel('vpn')->error("❌ {$server->name}: SSH connectivity failed", [
                'exit_code' => $sshTest['status'] ?? 'unknown',
            ]);
            return [];
        }

        $iface = $server->wg_interface ?? 'wg0';
        $cmd   = "wg show " . escapeshellarg($iface) . " dump";

        $res = $this->executeRemoteCommand($server, 'bash -lc ' . escapeshellarg($cmd));
        $out = $res['output'] ?? [];

        if (($res['status'] ?? 1) !== 0 || empty($out)) {
            Log::channel('vpn')->warning("⚠️ {$server->name}: wg show dump failed or returned no data", [
                'exit_code' => $res['status'] ?? 'unknown',
            ]);
            return [];
        }

        $nowTs = time();
        $peers = [];

        foreach (array_filter($out) as $line) {
            $parts = preg_split('/\s+/', trim($line));
            if (!$parts || count($parts) < 8) continue;

            // wg show dump format:
            // interface line: <interface_pub> <priv> <listen_port> <fwmark>
            // peer line:      <pub> <psk> <endpoint> <allowed_ips> <latest_hs> <rx> <tx> <keepalive>
            if (count($parts) === 4) {
                // interface header row -> skip
                continue;
            }

            [$pubKey, $psk, $endpoint, $allowedIps, $latestHs, $rxBytes, $txBytes] = $parts;

            // Basic validation: base64-ish key
            if (!is_string($pubKey) || !preg_match('#^[A-Za-z0-9+/=]{32,80}$#', $pubKey)) {
                continue;
            }

            $hs = (int) $latestHs;

            // handshake 0 means "never"
            if ($hs <= 0) {
                continue;
            }

            // Only ONLINE peers (handshake within window)
            if (($nowTs - $hs) > self::ONLINE_HANDSHAKE_SECONDS) {
                continue;
            }

            $clientIp = null;
            if ($endpoint && $endpoint !== '(none)') {
                // e.g. 1.2.3.4:51820 OR [2001:db8::1]:51820
                if (str_starts_with($endpoint, '[')) {
                    // IPv6 bracket form
                    $clientIp = trim(strtok(substr($endpoint, 1), ']'));
                } else {
                    $clientIp = explode(':', $endpoint)[0] ?? null;
                }
            }

            $virtualIp = null;
            if ($allowedIps && $allowedIps !== '(none)') {
                // allowedIps can be "10.66.66.2/32,fd00::2/128"
                $first = explode(',', $allowedIps)[0] ?? null;
                if ($first) $virtualIp = explode('/', $first)[0] ?? null;
            }

            $peers[] = [
                'public_key' => $pubKey,
                'client_ip'  => $clientIp,
                'virtual_ip' => $virtualIp,

                // match controller field expectations
                'bytes_in'   => (int) $rxBytes,
                'bytes_out'  => (int) $txBytes,

                // send as epoch seconds (controller parseTime supports it)
                'seen_at'    => $hs,
            ];
        }

        return $peers;
    }

    protected function pushSnapshot(int $serverId, \DateTimeInterface $ts, array $peers): void
    {
        try {
            Http::withToken(config('services.panel.token'))
                ->acceptJson()
                ->post(
                    rtrim(config('services.panel.base'), '/') . "/api/servers/{$serverId}/wireguard-events",
                    [
                        'status' => 'mgmt',
                        'ts'     => $ts->format(DATE_ATOM),
                        'peers'  => $peers,
                    ]
                )
                ->throw();

            Log::channel('vpn')->debug("[pushWgSnapshot] #{$serverId} sent " . count($peers) . " online peers");
        } catch (\Throwable $e) {
            Log::channel('vpn')->error("❌ Failed to POST /api/servers/{$serverId}/wireguard-events: {$e->getMessage()}");
        }
    }
}