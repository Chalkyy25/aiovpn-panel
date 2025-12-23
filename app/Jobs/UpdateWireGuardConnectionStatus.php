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
        Log::channel('vpn')->info(
            '🔄 WireGuard sync ' . ($this->serverId ? "(server {$this->serverId})" : '(fleet)')
        );

        // IMPORTANT:
        // Your vpn_servers.protocol is NOT reliable for WG (your WG peers live on servers stored as "openvpn").
        // So we poll all deployed servers and auto-detect WG support on the box.
        $servers = VpnServer::query()
            ->whereIn('deployment_status', ['succeeded', 'deployed'])
            ->when($this->serverId, fn ($q) => $q->whereKey($this->serverId))
            ->get();

        if ($servers->isEmpty()) {
            Log::channel('vpn')->warning(
                $this->serverId
                    ? "⚠️ No deployed server found with ID {$this->serverId}"
                    : "⚠️ No deployed servers found."
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
            // 1) Quick SSH check
            $ssh = $this->executeRemoteCommand($server, 'bash -lc ' . escapeshellarg('echo SSH_OK'));
            if (($ssh['status'] ?? 1) !== 0) {
                Log::channel('vpn')->warning("⏭ {$server->name}: SSH failed, skipping", [
                    'status' => $ssh['status'] ?? null,
                ]);
                return;
            }

            // 2) Detect WG availability on the server
            if (!$this->hasWireGuard($server)) {
                Log::channel('vpn')->debug("⏭ {$server->name}: no WireGuard detected, skipping");
                return;
            }

            // 3) Fetch peers
            $peers = $this->fetchPeers($server);

            Log::channel('vpn')->debug("[wg-sync] {$server->name} peers=" . count($peers), [
                'server_id' => $server->id,
                'sample'    => array_slice(array_column($peers, 'public_key'), 0, 5),
            ]);

            // 4) Push snapshot ALWAYS (even empty)
            $this->pushSnapshot($server->id, now(), $peers);

        } catch (\Throwable $e) {
            Log::channel('vpn')->error("❌ {$server->name}: WG sync failed – {$e->getMessage()}");
        }
    }

    protected function hasWireGuard(VpnServer $server): bool
    {
        // Prefer configured interface if you have it, else default
        $iface = $server->wg_interface ?? 'wg0';

        // wg binary + interface exists
        $cmd = 'bash -lc ' . escapeshellarg(
            "command -v wg >/dev/null 2>&1 && wg show " . escapeshellarg($iface) . " >/dev/null 2>&1 && echo YES || echo NO"
        );

        $res = $this->executeRemoteCommand($server, $cmd);
        $out = trim(($res['output'][0] ?? ''));

        return ($res['status'] ?? 1) === 0 && $out === 'YES';
    }

    protected function fetchPeers(VpnServer $server): array
    {
        $iface = $server->wg_interface ?? 'wg0';

        $cmd = 'bash -lc ' . escapeshellarg("wg show " . escapeshellarg($iface) . " dump");
        $res = $this->executeRemoteCommand($server, $cmd);

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

            // header line: interface_pub interface_priv listen_port fwmark
            if ($i === 0 && count($parts) >= 4) {
                continue;
            }

            // peer line: pub psk endpoint allowed_ips latest_handshake rx tx keepalive
            if (count($parts) < 8) {
                // fallback only if something truly weird happened
                $parts = preg_split('/\s+/', trim($line));
                if (count($parts) < 8) continue;
            }

            [$pub, $psk, $endpoint, $allowedIps, $handshakeRaw, $rx, $tx, $keepalive] = $parts;

            // allowed_ips can be multiple: "10.0.0.2/32,fd00::2/128" (sometimes spaces too)
            $virtualIp = null;
            if (!empty($allowedIps) && $allowedIps !== '(none)') {
                $first = trim(explode(',', $allowedIps, 2)[0] ?? '');
                $virtualIp = $first !== '' ? (explode('/', $first, 2)[0] ?? null) : null;
            }

            $clientIp = null;
            if (!empty($endpoint) && $endpoint !== '(none)') {
                $clientIp = explode(':', $endpoint, 2)[0] ?? null;
            }

            $handshakeSec = $this->normalizeHandshakeToSeconds($handshakeRaw);
            $seenAtIso = $handshakeSec > 0 ? gmdate('c', $handshakeSec) : null;

            $peers[] = [
                'proto'       => 'wireguard',
                'public_key'  => $pub,
                'endpoint'    => $endpoint,
                'client_ip'   => $clientIp,
                'virtual_ip'  => $virtualIp,

                // controller can use either seen_at or handshake; send both cleanly
                'handshake'   => $handshakeSec,     // seconds epoch (0 if none)
                'seen_at'     => $seenAtIso,         // ISO8601 or null

                'bytes_in'    => (int)$rx,
                'bytes_out'   => (int)$tx,
                'keepalive'   => ($keepalive === 'off' || $keepalive === '') ? null : (int)$keepalive,
            ];
        }

        return $peers;
    }

    protected function normalizeHandshakeToSeconds(string $raw): int
    {
        // wg dump gives epoch in seconds (usually), but handle ms/ns if you ever feed that in.
        $n = (int)$raw;
        if ($n <= 0) return 0;

        if ($n >= 1_000_000_000_000_000) { // ns
            return (int) floor($n / 1_000_000_000);
        }
        if ($n >= 1_000_000_000_000) { // ms
            return (int) floor($n / 1_000);
        }

        return $n; // seconds
    }

    protected function pushSnapshot(int $serverId, \DateTimeInterface $ts, array $peers): void
    {
        try {
            Log::channel('vpn')->debug('[pushWgSnapshot]', [
                'server_id'  => $serverId,
                'peer_count' => count($peers),
                'sample'     => array_slice(array_column($peers, 'public_key'), 0, 5),
            ]);

            Http::withToken(config('services.panel.token'))
                ->acceptJson()
                ->post(rtrim(config('services.panel.base'), '/') . "/api/servers/{$serverId}/wireguard-events", [
                    'status' => 'mgmt',
                    'ts'     => $ts->format(DATE_ATOM),
                    'peers'  => $peers,
                ])
                ->throw();

        } catch (\Throwable $e) {
            Log::channel('vpn')->error("❌ Failed to POST WG events for #{$serverId}: {$e->getMessage()}");
        }
    }
}