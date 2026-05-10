<?php

namespace App\Console\Commands;

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
                            {server? : VPN Server ID to poll}
                            {--interval=3 : Poll interval in seconds}
                            {--no-db : Skip DB snapshot persistence}
                            {--silent : Suppress console output}';

    protected $description = 'Near real-time VPN server poller';

    protected bool $shouldStop = false;

    protected array $lastStatus = [];

    public function handle(): int
    {
        $serverId = $this->argument('server');

        $interval = max(
            1,
            (int) $this->option('interval')
        );

        $skipDb = (bool) $this->option('no-db');

        $silent = (bool) $this->option('silent');

        if (! $silent) {
            $this->info(
                "🚀 Starting near real-time VPN poller ({$interval}s)"
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Graceful shutdown
        |--------------------------------------------------------------------------
        */

        if (function_exists('pcntl_async_signals')) {

            pcntl_async_signals(true);

            pcntl_signal(SIGTERM, fn () => $this->shouldStop = true);

            pcntl_signal(SIGINT, fn () => $this->shouldStop = true);
        }

        $iteration = 0;

        while (! $this->shouldStop) {

            $iteration++;

            $startedAt = microtime(true);

            try {

                $this->pollServers(
                    $serverId,
                    $skipDb,
                    $iteration,
                    $silent
                );

            } catch (\Throwable $e) {

                if (! $silent) {
                    $this->error(
                        "❌ Poll failed: {$e->getMessage()}"
                    );
                }

                Log::channel('vpn')->error(
                    "Poll failure: {$e->getMessage()}",
                    [
                        'trace' => $e->getTraceAsString(),
                    ]
                );
            }

            $elapsed = microtime(true) - $startedAt;

            $sleep = max(
                0.1,
                $interval - $elapsed
            );

            if (! $silent && $iteration % 20 === 0) {

                $this->line(sprintf(
                    "✓ Iteration %d completed in %.2fs",
                    $iteration,
                    $elapsed
                ));
            }

            usleep((int) ($sleep * 1_000_000));
        }

        $this->warn('⏹️ Poller stopped gracefully');

        return self::SUCCESS;
    }

    protected function pollServers(
        ?string $serverId,
        bool $skipDb,
        int $iteration,
        bool $silent
    ): void {

        $servers = VpnServer::query()
            ->whereIn('deployment_status', [
                'success',
                'deployed',
            ])
            ->when(
                $serverId,
                fn ($q) => $q->where('id', $serverId)
            )
            ->get();

        if ($servers->isEmpty()) {

            if ($iteration === 1 && ! $silent) {
                $this->warn('⚠️ No active VPN servers found');
            }

            return;
        }

        foreach ($servers as $server) {

            $this->pollOneServer(
                $server,
                $skipDb,
                $iteration,
                $silent
            );
        }
    }

    protected function pollOneServer(
        VpnServer $server,
        bool $skipDb,
        int $iteration,
        bool $silent
    ): void {

        try {

            [$raw, $source] = $this->fetchStatusWithSource($server);

            /*
            |--------------------------------------------------------------------------
            | Server unreachable
            |--------------------------------------------------------------------------
            */

            if ($raw === '') {

                $server->is_online = false;
                $server->save();

                if ($iteration === 1 && ! $silent) {
                    $this->warn(
                        "⚠️ {$server->name}: No status data available"
                    );
                }

                return;
            }

            /*
            |--------------------------------------------------------------------------
            | Parse VPN status
            |--------------------------------------------------------------------------
            */

            $parsed = OpenVpnStatusParser::parse($raw);

            $clients = $parsed['clients'] ?? [];

            $usernames = array_column(
                $clients,
                'username'
            );

            /*
            |--------------------------------------------------------------------------
            | Fetch node metrics
            |--------------------------------------------------------------------------
            */

            $metrics = $this->fetchNodeMetrics($server);

            /*
            |--------------------------------------------------------------------------
            | Persist metrics
            |--------------------------------------------------------------------------
            */

            $server->cpu_usage = $metrics['cpu'];

            $server->memory_usage = $metrics['memory_usage'];

            $server->load_average = $metrics['load'];

            $server->online_users = count($clients);

            $server->is_online = true;

            $server->last_sync_at = now();

            $server->save();

            logger()->info('SERVER METRICS SAVE', [
                'server' => $server->name,
                'cpu' => $server->cpu_usage,
                'memory' => $server->memory_usage,
                'load' => $server->load_average,
            ]);

            /*
            |--------------------------------------------------------------------------
            | Change detection
            |--------------------------------------------------------------------------
            */

            $currentHash = md5(
                json_encode($usernames)
            );

            $lastHash = $this->lastStatus[$server->id] ?? null;

            if (
                $currentHash !== $lastHash ||
                $iteration === 1
            ) {

                $this->lastStatus[$server->id] = $currentHash;

                /*
                |--------------------------------------------------------------------------
                | Push snapshots
                |--------------------------------------------------------------------------
                */

                if (! $skipDb) {

                    $this->pushSnapshot(
                        $server->id,
                        now(),
                        $clients
                    );
                }

                /*
                |--------------------------------------------------------------------------
                | Console output
                |--------------------------------------------------------------------------
                */

                if (! $silent) {

                    $this->info(sprintf(
                        '📡 %s: %d clients | CPU %.1f%% | RAM %.1f%% | Load %.2f',
                        $server->name,
                        count($clients),
                        $metrics['cpu'],
                        $metrics['memory_usage'],
                        $metrics['load']
                    ));
                }

                /*
                |--------------------------------------------------------------------------
                | Logging
                |--------------------------------------------------------------------------
                */

                Log::channel('vpn')->info(
                    "MGMT POLL: {$server->name}",
                    [
                        'users' => $usernames,
                        'count' => count($clients),
                        'cpu_usage' => $metrics['cpu'],
                        'memory_usage' => $metrics['memory_usage'],
                        'load_average' => $metrics['load'],
                        'source' => $source,
                        'iteration' => $iteration,
                    ]
                );
            }

        } catch (\Throwable $e) {

            $server->is_online = false;
            $server->save();

            if (! $silent) {

                $this->error(
                    "❌ {$server->name}: {$e->getMessage()}"
                );
            }

            Log::channel('vpn')->error(
                "Poll error for {$server->name}: {$e->getMessage()}"
            );
        }
    }

    protected function fetchNodeMetrics(
        VpnServer $server
    ): array {

        $cmd = <<<'BASH'
CPU=$(grep 'cpu ' /proc/stat | awk '{usage=($2+$4)*100/($2+$4+$5)} END {print usage}')

LOAD=$(cut -d " " -f1 /proc/loadavg)

MEMORY=$(free | awk '/Mem:/ {print ($3/$2) * 100.0}')

echo "CPU=$CPU"
echo "LOAD=$LOAD"
echo "MEMORY=$MEMORY"
BASH;

        $res = $this->executeRemoteCommand(
            $server,
            'bash -lc ' . escapeshellarg($cmd)
        );

        $output = implode(
            "\n",
            $res['output'] ?? []
        );

        preg_match('/CPU=([\d\.]+)/', $output, $cpu);

        preg_match('/LOAD=([\d\.]+)/', $output, $load);

        preg_match('/MEMORY=([\d\.]+)/', $output, $memory);

        return [
            'cpu' => isset($cpu[1])
                ? round((float) $cpu[1], 2)
                : 0,

            'load' => isset($load[1])
                ? round((float) $load[1], 2)
                : 0,

            'memory_usage' => isset($memory[1])
                ? round((float) $memory[1], 2)
                : 0,
        ];
    }

    protected function fetchStatusWithSource(
        VpnServer $server
    ): array {

        $mgmtPort = (int) (
            $server->mgmt_port ?? 7505
        );

        $mgmtPorts = [$mgmtPort];

        if ($mgmtPort === 7505) {
            $mgmtPorts[] = 7506;
        }

        /*
        |--------------------------------------------------------------------------
        | Quick SSH test
        |--------------------------------------------------------------------------
        */

        $sshTest = $this->executeRemoteCommand(
            $server,
            'bash -lc ' . escapeshellarg('echo SSH_OK')
        );

        if (($sshTest['status'] ?? 1) !== 0) {
            return ['', 'ssh_failed'];
        }

        /*
        |--------------------------------------------------------------------------
        | Status files
        |--------------------------------------------------------------------------
        */

        $statusFiles = [
            '/var/log/openvpn-status-udp.log',
            '/var/log/openvpn-status-tcp.log',
            '/run/openvpn/server.status',
            '/run/openvpn/server-tcp.status',
        ];

        foreach ($statusFiles as $path) {

            $cmd = 'bash -lc ' . escapeshellarg(
                "test -s {$path} && cat {$path} || echo '__NOFILE__'"
            );

            $res = $this->executeRemoteCommand(
                $server,
                $cmd
            );

            $data = trim(
                implode("\n", $res['output'] ?? [])
            );

            if (
                ($res['status'] ?? 1) === 0 &&
                $data !== '' &&
                $data !== '__NOFILE__' &&
                str_contains($data, 'CLIENT_LIST')
            ) {

                return [
                    $data,
                    basename($path),
                ];
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Management interface fallback
        |--------------------------------------------------------------------------
        */

        foreach ($mgmtPorts as $port) {

            $cmd =
                '(echo "status 3"; sleep 0.3; echo "quit") | nc -q 1 -w 2 127.0.0.1 ' .
                $port;

            $res = $this->executeRemoteCommand(
                $server,
                'bash -lc ' . escapeshellarg($cmd)
            );

            $out = trim(
                implode("\n", $res['output'] ?? [])
            );

            if (
                ($res['status'] ?? 1) === 0 &&
                str_contains($out, 'CLIENT_LIST')
            ) {

                return [
                    $out,
                    "mgmt:{$port}",
                ];
            }
        }

        return ['', 'none'];
    }

    protected function pushSnapshot(
        int $serverId,
        \DateTimeInterface $ts,
        array $clients
    ): void {

        /*
        |--------------------------------------------------------------------------
        | Never wipe dashboard with empty snapshot
        |--------------------------------------------------------------------------
        */

        if (count($clients) === 0) {

            $this->line(
                "server {$serverId}: clients=0 -> SKIP (prevent wipe)"
            );

            return;
        }

        try {

            Http::withToken(
                config('services.panel.token')
            )
                ->acceptJson()
                ->timeout(3)
                ->post(
                    config('services.panel.base') .
                    "/api/servers/{$serverId}/events",
                    [
                        'status' => 'mgmt',
                        'ts' => $ts->format(DATE_ATOM),
                        'users' => $clients,
                    ]
                )
                ->throw();

        } catch (\Throwable $e) {

            Log::channel('vpn')->error(
                "Push snapshot failed for server {$serverId}: {$e->getMessage()}"
            );
        }
    }
}