<?php

namespace App\Console\Commands;

use App\Events\ServerMgmtEvent;
use App\Models\VpnServer;
use App\Services\OpenVpnStatusParser;
use App\Traits\ExecutesRemoteCommands;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class VpnPollServer extends Command
{
    use ExecutesRemoteCommands;

    protected $signature = 'vpn:poll-server 
                            {server? : VPN Server ID to poll, or leave empty for all servers}
                            {--interval=3 : Poll interval in seconds (default: 3)}
                            {--no-db : Skip database persistence (broadcast only)}
                            {--silent : Suppress output except for changes}';

    protected $description = 'Near real-time VPN server status poller (runs continuously)';

    protected bool $shouldStop = false;
    protected array $lastStatus = [];

    public function handle(): int
    {
        $serverId = $this->argument('server');
        $interval = max(1, (int)$this->option('interval'));
        $skipDb = $this->option('no-db');
        $silent = $this->option('silent');

        if (!$silent) {
            $this->info("🚀 Starting near real-time VPN poller (interval: {$interval}s)");
        }

        // Handle graceful shutdown
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, fn() => $this->shouldStop = true);
            pcntl_signal(SIGINT, fn() => $this->shouldStop = true);
        }

        $iteration = 0;
        while (!$this->shouldStop) {
            $iteration++;
            $startTime = microtime(true);

            try {
                $this->pollServers($serverId, $skipDb, $iteration, $silent);
            } catch (\Throwable $e) {
                if (!$silent) {
                    $this->error("❌ Poll failed: {$e->getMessage()}");
                }
                Log::channel('vpn')->error("Poll error: {$e->getMessage()}", [
                    'trace' => $e->getTraceAsString()
                ]);
            }

            $elapsed = microtime(true) - $startTime;
            $sleepTime = max(0.1, $interval - $elapsed);

            // Show heartbeat every 20 iterations (1 minute at 3s interval)
            if (!$silent && $iteration % 20 === 0) {
                $this->line(sprintf(
                    "✓ Iteration %d completed in %.2fs",
                    $iteration,
                    $elapsed
                ));
            }

            usleep((int)($sleepTime * 1000000));
        }

        $this->warn("⏹️  Poller stopped gracefully");
        return 0;
    }

    protected function pollServers(?string $serverId, bool $skipDb, int $iteration, bool $silent): void
    {
        $servers = VpnServer::query()
            ->whereIn('deployment_status', ['succeeded', 'deployed'])
            ->when($serverId, fn($q) => $q->where('id', $serverId))
            ->get();

        if ($servers->isEmpty()) {
            if ($iteration === 1 && !$silent) {
                $this->warn("⚠️ No active VPN servers found");
            }
            return;
        }

        foreach ($servers as $server) {
            $this->pollOneServer($server, $skipDb, $iteration, $silent);
        }
    }

    protected function pollOneServer(VpnServer $server, bool $skipDb, int $iteration, bool $silent): void
    {
        try {
            [$raw, $source] = $this->fetchStatusWithSource($server);

            if ($raw === '') {
                if ($iteration === 1 && !$silent) {
                    $this->warn("⚠️ {$server->name}: No status data available");
                }
                return;
            }

            $parsed = OpenVpnStatusParser::parse($raw);
            $clients = $parsed['clients'] ?? [];
            $usernames = array_column($clients, 'username');

            // Check if status changed (only log/broadcast on change for efficiency)
            $currentHash = md5(json_encode($usernames));
            $lastHash = $this->lastStatus[$server->id] ?? null;

            if ($currentHash !== $lastHash || $iteration === 1) {
                $this->lastStatus[$server->id] = $currentHash;

                // Broadcast event for real-time dashboard updates
                broadcast(new ServerMgmtEvent(
                    $server->id,
                    now()->toIso8601String(),
                    $clients,
                    null,
                    $raw
                ));

                // Persist to database unless --no-db flag is set
                if (!$skipDb) {
                    $this->pushSnapshot($server->id, now(), $clients);
                }

                // Show change notification
                if (!$silent) {
                    $this->info(sprintf(
                        "📡 %s: %d clients [%s] (source: %s)",
                        $server->name,
                        count($clients),
                        implode(', ', $usernames) ?: 'none',
                        $source
                    ));
                }

                Log::channel('vpn')->info("MGMT POLL: {$server->name} clients=" . count($clients), [
                    'users' => $usernames,
                    'source' => $source,
                    'iteration' => $iteration
                ]);
            }

        } catch (\Throwable $e) {
            if (!$silent) {
                $this->error("❌ {$server->name}: {$e->getMessage()}");
            }
            Log::channel('vpn')->error("Poll error for {$server->name}: {$e->getMessage()}");
        }
    }

    protected function fetchStatusWithSource(VpnServer $server): array
    {
        $mgmtPort = (int)($server->mgmt_port ?? 7505);
        $mgmtPorts = [$mgmtPort];
        if ($mgmtPort == 7505) {
            $mgmtPorts[] = 7506; // TCP stealth port
        }

        // Test SSH connectivity (fast check)
        $testCmd = 'bash -lc ' . escapeshellarg('echo "SSH_OK"');
        $sshTest = $this->executeRemoteCommand($server, $testCmd);

        if (($sshTest['status'] ?? 1) !== 0) {
            return ['', 'ssh_failed'];
        }

        // Try status files first (most reliable, updated every 10s by OpenVPN)
        $statusFiles = [
            '/var/log/openvpn-status-udp.log',      // Primary UDP status file
            '/var/log/openvpn-status-tcp.log',      // TCP stealth status file
            '/run/openvpn/server.status',           // Legacy UDP location
            '/run/openvpn/server-tcp.status',       // Legacy TCP location
        ];

        foreach ($statusFiles as $path) {
            $cmd = 'bash -lc ' . escapeshellarg("test -s {$path} && cat {$path} || echo '__NOFILE__'");
            $res = $this->executeRemoteCommand($server, $cmd);
            $data = trim(implode("\n", $res['output'] ?? []));

            if (($res['status'] ?? 1) === 0 
                && $data !== '' 
                && $data !== '__NOFILE__' 
                && str_contains($data, 'CLIENT_LIST')) {
                return [$data, basename($path)];
            }
        }

        // Fallback to management interface (less reliable due to single-connection limit)
        foreach ($mgmtPorts as $port) {
            // Fast query with minimal timeout
            $cmd = '(echo "status 3"; sleep 0.3; echo "quit") | nc -q 1 -w 2 127.0.0.1 ' . $port;
            $res = $this->executeRemoteCommand($server, 'bash -lc ' . escapeshellarg($cmd));
            $out = trim(implode("\n", $res['output'] ?? []));

            if (($res['status'] ?? 1) === 0 && str_contains($out, "CLIENT_LIST")) {
                $clientCount = substr_count($out, "CLIENT_LIST") - 1;
                if ($clientCount > 0) {
                    return [$out, "mgmt:{$port}"];
                }
                $fallbackResponse = [$out, "mgmt:{$port}"];
            }
        }

        return $fallbackResponse ?? ['', 'none'];
    }

    protected function pushSnapshot(int $serverId, \DateTimeInterface $ts, array $clients): void
    {
        try {
            Http::withToken(config('services.panel.token'))
                ->acceptJson()
                ->timeout(3)
                ->post(config('services.panel.base') . "/api/servers/{$serverId}/events", [
                    'status' => 'mgmt',
                    'ts'     => $ts->format(DATE_ATOM),
                    'users'  => $clients,
                ])
                ->throw();

        } catch (\Throwable $e) {
            Log::channel('vpn')->error("Push snapshot failed for server {$serverId}: {$e->getMessage()}");
        }
    }
}