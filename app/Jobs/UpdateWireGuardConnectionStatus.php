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

        // ✅ Your DB uses supports_wireguard (protocol stays "openvpn" even when WG exists)
        $servers = VpnServer::query()
            ->whereIn('deployment_status', ['succeeded', 'deployed'])
            ->where('supports_wireguard', 1)
            ->when($this->serverId, fn ($q) => $q->whereKey($this->serverId))
            ->get();

        if ($servers->isEmpty()) {
            Log::channel('vpn')->warning(
                $this->serverId
                    ? "⚠️ No WireGuard-enabled server found with ID {$this->serverId}"
                    : "⚠️ No WireGuard-enabled servers found."
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
            // 1) SSH smoke test
            $ssh = $this->executeRemoteCommand($server, 'bash -lc ' . escapeshellarg('echo SSH_OK'));
            if (($ssh['status'] ?? 1) !== 0) {
                Log::channel('vpn')->warning("⏭ {$server->name}: SSH failed, skipping");
                return;
            }

            // 2) Only proceed if WG exists AND wg0 exists
            // IMPORTANT: do NOT POST empty snapshots if WG is not actually available.
            if (!$this->wgInterfaceExists($server, 'wg0')) {
                Log::channel('vpn')->debug("⏭ {$server->name}: wg0 not present, skipping (no POST)");
                return;
            }

            // 3) Fetch peers (this must succeed, otherwise skip without POST)
            $peers = $this->fetchPeers($server, 'wg0');

            Log::channel('vpn')->debug("[wg-sync] {$server->name} peers=" . count($peers), [
                'server_id' => $server->id,
                'sample'    => array_slice(array_column($peers, 'public_key'), 0, 5),
            ]);

            // 4) POST snapshot (authoritative)
            $this->pushSnapshot($server->id, now(), $peers);

        } catch (\Throwable $e) {
            Log::channel('vpn')->error("❌ {$server->name}: WG sync failed – {$e->getMessage()}");
        }
    }

    protected function wgInterfaceExists(VpnServer $server, string $iface): bool
    {
        $cmd = 'bash -lc ' . escapeshellarg(
            "command -v wg >/dev/null 2>&1 && wg show " . escapeshellarg($iface) . " >/dev/null 2>&1 && echo YES || echo NO"
        );

        $res = $this->executeRemoteCommand($server, $cmd);
        $out = trim((string)($res['output'][0] ?? ''));

        return ($res['status'] ?? 1) === 0 && $out === 'YES';
    }

    protected function fetchPeers(VpnServer $server, string $iface): array
    {
        $cmd = 'bash -lc ' . escapeshellarg("wg show " . escapeshellarg($iface) . " dump");
        $res = $this->executeRemoteCommand($server, $cmd);

        // ✅ If wg dump fails, DO NOT return [] and DO NOT POST. That wipes state.
        if (($res['status'] ?? 1) !== 0) {
            throw new \RuntimeException("wg show dump failed (status=" . ($res['status'] ?? 'null') . ")");
        }

        $lines = array_values(array_filter($res['output'] ?? [], fn ($l) => trim((string)$l) !== ''));
        if (!$lines) return [];

        $peers = [];

        foreach ($lines as $i => $line) {
            $line = rtrim((string)$line, "\r\n");

            // wg dump is TAB-delimited
            $parts = explode("\t", $line);

            // header: interface_pub interface_priv listen_port fwmark
            if ($i === 0 && count($parts) >= 4) continue;

            // peer: pub psk endpoint allowed_ips latest_handshake rx tx keepalive
            if (count($parts) < 8) continue;

            [$pub, $psk, $endpoint, $allowedIps, $handshakeRaw, $rx, $tx, $keepalive] = $parts;

            $virtualIp = null;
            if (!empty($allowedIps) && $allowedIps !== '(none)') {
                $first = trim(explode(',', $allowedIps, 2)[0] ?? '');
                $virtualIp = $first !== '' ? (explode('/', $first, 2)[0] ?? null) : null;
            }

            $clientIp = null;
            if (!empty($endpoint) && $endpoint !== '(none)') {
                $clientIp = explode(':', $endpoint, 2)[0] ?? null;
            }

            $handshakeSec = $this->normalizeHandshakeToSeconds((string)$handshakeRaw);
            $seenAtIso = $handshakeSec > 0 ? gmdate('c', $handshakeSec) : null;

            $peers[] = [
                'proto'       => 'wireguard',
                'public_key'  => $pub,
                'endpoint'    => $endpoint,
                'client_ip'   => $clientIp,
                'virtual_ip'  => $virtualIp,
                'handshake'   => $handshakeSec, // epoch seconds
                'seen_at'     => $seenAtIso,     // ISO8601
                'bytes_in'    => (int)$rx,
                'bytes_out'   => (int)$tx,
                'keepalive'   => ($keepalive === 'off' || $keepalive === '') ? null : (int)$keepalive,
            ];
        }

        return $peers;
    }

    protected function normalizeHandshakeToSeconds(string $raw): int
    {
        $n = (int)$raw;
        if ($n <= 0) return 0;

        if ($n >= 1_000_000_000_000_000) return (int) floor($n / 1_000_000_000); // ns
        if ($n >= 1_000_000_000_000)     return (int) floor($n / 1_000);         // ms
        return $n; // seconds
    }

    protected function pushSnapshot(int $serverId, \DateTimeInterface $ts, array $peers): void
    {
        Http::withToken(config('services.panel.token'))
            ->acceptJson()
            ->post(rtrim(config('services.panel.base'), '/') . "/api/servers/{$serverId}/wireguard-events", [
                'status' => 'mgmt',
                'ts'     => $ts->format(DATE_ATOM),
                'peers'  => $peers,
            ])
            ->throw();

        Log::channel('vpn')->debug("[pushWgSnapshot] #{$serverId} sent " . count($peers) . " peers");
    }
}