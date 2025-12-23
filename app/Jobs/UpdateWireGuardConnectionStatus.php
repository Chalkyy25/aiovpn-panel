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

    public function __construct(protected ?int $serverId = null) {}

    public function handle(): void
    {
        Log::channel('vpn')->info('🔄 WireGuard sync ' . ($this->serverId ? "(server {$this->serverId})" : '(fleet)'));

        $servers = VpnServer::query()
            ->whereIn('deployment_status', ['succeeded', 'deployed'])
            ->when($this->serverId, fn ($q) => $q->whereKey($this->serverId))
            // be tolerant of stored values
            ->whereRaw('LOWER(protocol) LIKE ?', ['%wireguard%'])
            ->get();

        if ($servers->isEmpty()) {
            Log::channel('vpn')->warning($this->serverId
                ? "⚠️ No WireGuard server found with ID {$this->serverId}"
                : "⚠️ No WireGuard servers found."
            );
            return;
        }

        foreach ($servers as $server) {
            $this->syncOne($server);
        }

        Log::channel('vpn')->info('✅ WireGuard sync completed');
    }

    protected function syncOne(VpnServer $server): void
    {
        try {
            $peers = $this->fetchPeers($server);

            Log::channel('vpn')->debug("[wg-sync] {$server->name} peers=" . count($peers), [
                'sample' => array_slice(array_column($peers, 'public_key'), 0, 5),
            ]);

            $this->pushSnapshot($server->id, now(), $peers);
        } catch (\Throwable $e) {
            Log::channel('vpn')->error("❌ {$server->name}: WG sync failed – {$e->getMessage()}");
        }
    }

    protected function fetchPeers(VpnServer $server): array
    {
        $interface = $server->wg_interface ?: 'wg0';

        $sshTest = $this->executeRemoteCommand($server, 'bash -lc ' . escapeshellarg('echo SSH_TEST_OK'));
        if (($sshTest['status'] ?? 1) !== 0) {
            throw new \RuntimeException("SSH connectivity failed (status=" . ($sshTest['status'] ?? 'null') . ")");
        }

        $cmd = "wg show " . escapeshellarg($interface) . " dump";
        $res = $this->executeRemoteCommand($server, 'bash -lc ' . escapeshellarg($cmd));
        $lines = array_values(array_filter($res['output'] ?? [], fn ($l) => trim((string)$l) !== ''));

        if (($res['status'] ?? 1) !== 0) {
            throw new \RuntimeException("wg show dump failed (status=" . ($res['status'] ?? 'null') . ")");
        }

        // A successful dump can legitimately contain only the interface header (no peers).
        if (empty($lines)) {
            return [];
        }

        $peers = [];

        foreach ($lines as $i => $line) {
            $line = trim((string) $line);

            // `wg show <iface> dump` is TAB-delimited.
            // Do not split on generic whitespace: allowed_ips can contain spaces (e.g. "a/b, c/d"),
            // which would shift columns and corrupt handshake/bytes fields.
            $parts = explode("\t", $line);
            if (count($parts) < 2) {
                // Fallback for unexpected formatting
                $parts = preg_split('/\s+/', $line);
            }

            // header line usually starts with "server_pubkey server_privkey listen_port fwmark"
            if ($i === 0 && count($parts) >= 4) continue;

            // peer lines: pubkey psk endpoint allowed_ips latest_handshake rx tx keepalive
            if (count($parts) < 8) continue;

            [$pubKey, $psk, $endpoint, $allowedIps, $handshakeRaw, $rxBytes, $txBytes, $keepAlive] = $parts;

            $virtualIp = null;
            if (!empty($allowedIps) && $allowedIps !== '(none)') {
                $virtualIp = explode('/', $allowedIps, 2)[0] ?? null;
            }

            $clientIp = null;
            if (!empty($endpoint) && $endpoint !== '(none)') {
                $clientIp = explode(':', $endpoint, 2)[0] ?? null;
            }

            $handshake = $this->normalizeHandshakeToSeconds($handshakeRaw);
            $seenAtIso = $handshake > 0 ? gmdate('c', $handshake) : null;

            $peers[] = [
                'public_key'  => $pubKey,
                'endpoint'    => $endpoint,
                'client_ip'   => $clientIp,
                'virtual_ip'  => $virtualIp,
                'handshake'   => $handshake, // seconds since epoch (0 if none)
                'seen_at'     => $seenAtIso,
                'bytes_in'    => (int) $rxBytes,
                'bytes_out'   => (int) $txBytes,
                'keepalive'   => ($keepAlive === 'off') ? null : (int) $keepAlive,
                'proto'       => 'wireguard',
            ];
        }

        return $peers;
    }

    protected function normalizeHandshakeToSeconds(string $raw): int
    {
        // raw can be: 0, seconds (~1e9), ms (~1e12), ns (~1e18)
        $n = (int) $raw;
        if ($n <= 0) return 0;

        if ($n >= 1_000_000_000_000_000) {          // ns
            return (int) floor($n / 1_000_000_000);
        }
        if ($n >= 1_000_000_000_000) {              // ms
            return (int) floor($n / 1_000);
        }
        return $n;                                   // seconds
    }

    protected function pushSnapshot(int $serverId, \DateTimeInterface $ts, array $peers): void
    {
        try {
            $publicKeys = array_values(array_filter(array_map(
                fn ($p) => $p['public_key'] ?? null,
                $peers
            )));

            Log::channel('vpn')->debug('[pushWgSnapshot payload]', [
                'server_id'    => $serverId,
                'peer_count'   => count($peers),
                'public_keys'  => $publicKeys,
            ]);

            Http::withToken(config('services.panel.token'))
                ->acceptJson()
                ->post(rtrim(config('services.panel.base'), '/') . "/api/servers/{$serverId}/wireguard-events", [
                    'status' => 'mgmt',
                    'ts'     => $ts->format(DATE_ATOM),
                    'peers'  => $peers,
                ])
                ->throw();

            Log::channel('vpn')->debug("[pushWgSnapshot] #{$serverId} sent " . count($peers) . " peers");
        } catch (\Throwable $e) {
            Log::channel('vpn')->error("❌ Failed to POST WG events for #{$serverId}: {$e->getMessage()}");
        }
    }
}