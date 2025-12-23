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
            ->where('protocol', 'wireguard')
            ->whereIn('deployment_status', ['succeeded', 'deployed'])
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
            $peers = $this->fetchWireGuardPeers($server);

            if (empty($peers)) {
                Log::channel('vpn')->debug("🟡 {$server->name}: No WireGuard peers found");
                return;
            }

            Log::channel('vpn')->debug(
                "[wg-sync] {$server->name} peers=" . count($peers),
                ['sample' => array_slice(array_column($peers, 'public_key'), 0, 5)]
            );

            // Push to WireGuard API endpoint
            $this->pushSnapshot($server->id, now(), $peers);
        } catch (\Throwable $e) {
            Log::channel('vpn')->error("❌ {$server->name}: WireGuard sync failed – {$e->getMessage()}");
        }
    }

    protected function fetchWireGuardPeers(VpnServer $server): array
    {
        // SSH smoke test
        $sshTest = $this->executeRemoteCommand($server, 'bash -lc ' . escapeshellarg('echo "SSH_TEST_OK"'));
        if (($sshTest['status'] ?? 1) !== 0) {
            Log::channel('vpn')->error("❌ {$server->name}: SSH connectivity failed");
            return [];
        }

        // Fetch WireGuard status via wg show
        $interface = $server->wg_interface ?? 'wg0'; // Default to wg0
        $cmd = sprintf('wg show %s dump', escapeshellarg($interface));
        
        $res = $this->executeRemoteCommand($server, 'bash -lc ' . escapeshellarg($cmd));
        
        if (($res['status'] ?? 1) !== 0 || empty($res['output'])) {
            Log::channel('vpn')->warning("⚠️ {$server->name}: wg show failed or returned no data");
            return [];
        }

        $output = array_filter($res['output']);
        $peers = [];

        // Parse wg show dump format
        // Line format: public_key preshared_key endpoint allowed_ips latest_handshake rx_bytes tx_bytes persistent_keepalive
        foreach ($output as $line) {
            $parts = preg_split('/\s+/', trim($line));
            if (count($parts) < 8) continue;

            [$pubKey, $psk, $endpoint, $allowedIps, $handshake, $rxBytes, $txBytes] = $parts;

            // Skip server's own public key (first line)
            if ($endpoint === '(none)' && $allowedIps === '(none)') continue;

            $handshakeTime = (int)$handshake;
            
            // Extract virtual IP from allowed_ips
            $virtualIp = null;
            if ($allowedIps && $allowedIps !== '(none)') {
                // Format like: 10.8.0.2/32
                $virtualIp = explode('/', $allowedIps)[0] ?? null;
            }

            // Extract client IP from endpoint
            $clientIp = null;
            if ($endpoint && $endpoint !== '(none)') {
                // Format like: 1.2.3.4:12345
                $clientIp = explode(':', $endpoint)[0] ?? null;
            }

            // Convert handshake timestamp to seen_at
            $seenAt = $handshakeTime > 0 
                ? date('c', $handshakeTime) 
                : null;

            $peers[] = [
                'public_key'     => $pubKey,
                'client_ip'      => $clientIp,
                'virtual_ip'     => $virtualIp,
                'bytes_received' => (int)$rxBytes,
                'bytes_sent'     => (int)$txBytes,
                'seen_at'        => $seenAt,
                'protocol'       => 'wireguard',
            ];
        }

        return $peers;
    }

    protected function pushSnapshot(int $serverId, \DateTimeInterface $ts, array $peers): void
    {
        try {
            Http::withToken(config('services.panel.token'))
                ->acceptJson()
                ->post(config('services.panel.base') . "/api/servers/{$serverId}/wireguard-events", [
                    'status' => 'mgmt',
                    'ts'     => $ts->format(DATE_ATOM),
                    'peers'  => $peers,
                ])
                ->throw();

            Log::channel('vpn')->debug("[pushWgSnapshot] #{$serverId} sent " . count($peers) . " peers");
        } catch (\Throwable $e) {
            Log::channel('vpn')->error("❌ Failed to POST /api/servers/{$serverId}/wireguard-events: {$e->getMessage()}");
        }
    }
}
