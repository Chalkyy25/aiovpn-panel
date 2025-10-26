<?php

namespace App\Jobs;

use App\Events\ServerMgmtEvent;
use App\Models\VpnServer;
use App\Services\OpenVpnStatusParser;
use App\Traits\ExecutesRemoteCommands;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class UpdateVpnConnectionStatus implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, ExecutesRemoteCommands;

    protected ?int $serverId;
    protected bool $strictOfflineOnMissing = false;
    protected bool $verboseMgmtLog;

    public function __construct(?int $serverId = null)
    {
        $this->serverId = $serverId;
        $this->verboseMgmtLog = (config('app.env') !== 'production')
            ? true
            : config('app.vpn_log_verbose', true);
    }

    public function handle(): void
    {
        Log::channel('vpn')->info('🔄 Hybrid sync: updating VPN connection status ' .
            ($this->serverId ? "(server {$this->serverId})" : '(fleet)')
        );

        /** @var Collection<int,VpnServer> $servers */
        $servers = VpnServer::query()
            ->whereIn('deployment_status', ['succeeded', 'deployed'])
            ->when($this->serverId, fn($q) => $q->where('id', $this->serverId))
            ->get();

        if ($servers->isEmpty()) {
            Log::channel('vpn')->warning($this->serverId
                ? "⚠️ No VPN server found with ID {$this->serverId}"
                : "⚠️ No succeeded VPN servers found."
            );
            return;
        }

        foreach ($servers as $server) {
            $this->syncOneServer($server);
        }

        Log::channel('vpn')->info('✅ Hybrid sync completed');
    }

    /* ─────────────────────────────────────────────── */

    protected function syncOneServer(VpnServer $server): void
    {
        try {
            [$raw, $source] = $this->fetchStatusWithSource($server);

            if ($raw === '') {
                Log::channel('vpn')->warning("🟡 {$server->name}: RAW EMPTY, skipping");
                return;
            }

            $parsed  = OpenVpnStatusParser::parse($raw);
            $clients = $parsed['clients'] ?? [];

            broadcast(new ServerMgmtEvent(
                $server->id,
                now()->toIso8601String(),
                $clients,
                null,
                $raw
            ));

            $usernames = array_column($clients, 'username');

            Log::channel('vpn')->debug("[sync] {$server->name} source={$source} clients=" . count($clients), [
                'users' => $usernames
            ]);

            if ($this->verboseMgmtLog) {
                Log::channel('vpn')->debug(sprintf(
                    '[mgmt] ts=%s source=%s clients=%d [%s]',
                    now()->toIso8601String(),
                    $source,
                    count($clients),
                    implode(',', $usernames)
                ));
            }

            $this->pushSnapshot($server->id, now(), $clients);

        } catch (\Throwable $e) {
            Log::channel('vpn')->error("❌ {$server->name}: sync failed – {$e->getMessage()}");
            if ($this->strictOfflineOnMissing) {
                $this->pushSnapshot($server->id, now(), []);
            }
        }
    }

    protected function fetchStatusWithSource(VpnServer $server): array
{
    $mgmtPort = (int)($server->mgmt_port ?? 7505);
    
    // Check both UDP (7505) and TCP (7506) management interfaces
    $mgmtPorts = [$mgmtPort];
    if ($mgmtPort == 7505) {
        $mgmtPorts[] = 7506; // Add TCP stealth server management port
    }

    Log::channel('vpn')->debug("🔍 {$server->name}: Starting status fetch", [
        'mgmt_ports' => $mgmtPorts,
        'ip'        => $server->ip_address,
        'ssh_user'  => $server->ssh_user ?? 'root',
    ]);

    // --- Test SSH connectivity first ---
    $testCmd = 'bash -lc ' . escapeshellarg('echo "SSH_TEST_OK"');
    $sshTest = $this->executeRemoteCommand($server, $testCmd);

    if (($sshTest['status'] ?? 1) !== 0) {
        Log::channel('vpn')->error("❌ {$server->name}: SSH connectivity failed", [
            'exit_code' => $sshTest['status'] ?? 'unknown',
        ]);
        return ['', 'ssh_failed'];
    }

    // --- Try mgmt interface first (check both UDP and TCP ports) ---
    foreach ($mgmtPorts as $port) {
        $mgmtCmds = [
            '( printf "status 3\n"; sleep 1; printf "quit\n" ) | nc -w 5 127.0.0.1 ' . $port,
            'echo -e "status 3\nquit\n" | nc -w 3 127.0.0.1 ' . $port,
            'echo -e "status\nquit\n" | nc -w 3 127.0.0.1 ' . $port,
        ];

        foreach ($mgmtCmds as $cmd) {
            $res = $this->executeRemoteCommand($server, 'bash -lc ' . escapeshellarg($cmd));
            $out = trim(implode("\n", $res['output'] ?? []));
            if (($res['status'] ?? 1) === 0 && str_contains($out, "CLIENT_LIST")) {
                // Count actual client connections (exclude header line)
                $clientCount = substr_count($out, "CLIENT_LIST") - 1;
                
                Log::channel('vpn')->debug("📡 {$server->name}: mgmt responded on port {$port} ({$clientCount} clients, " . strlen($out) . " bytes)", [
                    'preview' => substr($out, 0, 200) . '...',
                ]);
                
                // If we found connections, use this data; otherwise continue checking other ports
                if ($clientCount > 0) {
                    return [$out, "mgmt:{$port}"];
                }
                
                // Store the response in case no other port has connections
                $fallbackResponse = [$out, "mgmt:{$port}"];
            }
        }
    }
    
    // If we found a response but no active connections, use the last valid response
    if (isset($fallbackResponse)) {
        return $fallbackResponse;
    }

    // --- Fallback: status log files (check both UDP and TCP status files) ---
    $statusFiles = [
        '/run/openvpn/server.status',           // UDP server
        '/run/openvpn/server-tcp.status',       // TCP stealth server  
        '/var/log/openvpn-status-tcp.log',      // TCP server log
        '/etc/openvpn/openvpn-status.log'       // Generic status file
    ];
    
    foreach ($statusFiles as $path) {
        $cmd = 'bash -lc ' . escapeshellarg("test -s {$path} && cat {$path} || echo '__NOFILE__'");
        $res = $this->executeRemoteCommand($server, $cmd);
        $data = trim(implode("\n", $res['output'] ?? []));
        if (($res['status'] ?? 1) === 0 && $data !== '' && $data !== '__NOFILE__') {
            Log::channel('vpn')->debug("📄 {$server->name}: using status file {$path} (" . strlen($data) . " bytes)", [
                'preview' => substr($data, 0, 200) . '...',
            ]);
            return [$data, $path];
        }
    }

    Log::channel('vpn')->error("❌ {$server->name}: All methods failed - no mgmt or status file available");
    return ['', 'none'];
}
    protected function pushSnapshot(int $serverId, \DateTimeInterface $ts, array $clients): void
    {
        try {
            Http::withToken(config('services.panel.token'))
                ->acceptJson()
                ->post(config('services.panel.base') . "/api/servers/{$serverId}/events", [
                    'status' => 'mgmt',
                    'ts'     => $ts->format(DATE_ATOM),
                    'users'  => $clients,
                ])
                ->throw();

            Log::channel('vpn')->debug("[pushSnapshot] #{$serverId} sent " . count($clients) . " clients");

        } catch (\Throwable $e) {
            Log::channel('vpn')->error("❌ Failed to POST /api/servers/{$serverId}/events: {$e->getMessage()}");
        }
    }
}