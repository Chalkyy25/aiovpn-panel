<?php

namespace App\Jobs;

use App\Models\VpnServer;
use App\Services\OpenVpnStatusParser;
use App\Traits\ExecutesRemoteCommands;
use Carbon\Carbon;
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
        $this->verboseMgmtLog = (bool) (config('app.env') !== 'production'
            ? true
            : config('app.vpn_log_verbose', true));
    }

    public function handle(): void
    {
        Log::info('🔄 Hybrid sync: updating VPN connection status' . ($this->serverId ? " (server {$this->serverId})" : ' (fleet)'));

        /** @var Collection<int,VpnServer> $servers */
        $servers = VpnServer::query()
            ->where('deployment_status', 'succeeded')
            ->when($this->serverId, fn ($q) => $q->where('id', $this->serverId))
            ->get();

        if ($servers->isEmpty()) {
            Log::warning($this->serverId
                ? "⚠️ No VPN server found with ID {$this->serverId}"
                : "⚠️ No succeeded VPN servers found.");
            return;
        }

        foreach ($servers as $server) {
            $this->syncOneServer($server);
        }

        Log::info('✅ Hybrid sync completed');
    }

    /* ───────────────────────────────────────────────────────────── */

    protected function syncOneServer(VpnServer $server): void
{
    try {
        Log::info("🟢 ENTERED syncOneServer for {$server->name}");

[$raw, $source] = $this->fetchStatusWithSource($server);

Log::info("🟢 After fetchStatusWithSource", [
    'raw_len' => strlen($raw),
    'src'     => $source,
]);

if ($raw === '') {
    Log::info("🟡 {$server->name}: RAW EMPTY, skipping");
    return;
}

$parsed = OpenVpnStatusParser::parse($raw);

Log::info("🟢 After parse", [
    'clients_count' => count($parsed['clients'] ?? []),
    'first_client'  => $parsed['clients'][0] ?? null,
]);

$usernames = [];
foreach ($parsed['clients'] as $c) {
    $usernames[] = $c['username'] ?? '??';
}

Log::info("🟢 Collected usernames", [
    'count' => count($usernames),
    'names' => $usernames,
    'verboseFlag' => $this->verboseMgmtLog,
]);

Log::info("APPEND_LOG: forced line regardless of flag", [
    'server' => $server->id,
    'ts'     => now()->toIso8601String(),
    'names'  => $usernames,
]);

        if ($this->verboseMgmtLog) {
            Log::info(sprintf(
                'APPEND_LOG: [mgmt] ts=%s source=%s clients=%d [%s]',
                now()->toIso8601String(),
                $source,
                count($usernames),
                implode(',', $usernames)
            ));
        }

        // snapshot → API
        $this->pushSnapshot($server->id, now(), $usernames);

    } catch (\Throwable $e) {
        Log::error("❌ {$server->name}: sync failed – {$e->getMessage()}");
        if ($this->strictOfflineOnMissing) {
            $this->pushSnapshot($server->id, now(), []);
        }
    }
}

    protected function fetchStatusWithSource(VpnServer $server): array
{
    $mgmtPort = (int)($server->mgmt_port ?? 7505);

    Log::info("🔍 {$server->name}: Starting status fetch", [
        'mgmt_port' => $mgmtPort,
        'ip' => $server->ip_address,
        'ssh_user' => $server->ssh_user ?? 'root',
        'status_log_path' => $server->status_log_path ?? 'null'
    ]);

    // --- Test SSH connectivity first ---
    $testCmd = 'bash -lc ' . escapeshellarg('echo "SSH_TEST_OK"');
    $sshTest = $this->executeRemoteCommand($server, $testCmd);

    if (($sshTest['status'] ?? 1) !== 0) {
        Log::error("❌ {$server->name}: SSH connectivity failed", [
            'exit_code' => $sshTest['status'] ?? 'unknown',
            'output' => $sshTest['output'] ?? []
        ]);
        return ['', 'ssh_failed'];
    }

    Log::info("✅ {$server->name}: SSH connectivity confirmed");

    // --- Check if management port is listening ---
    $portCheckCmd = 'bash -lc ' . escapeshellarg(
        'netstat -ln 2>/dev/null | grep ":' . $mgmtPort . ' " || ss -ln 2>/dev/null | grep ":' . $mgmtPort . ' " || echo "PORT_NOT_LISTENING"'
    );
    $portCheck = $this->executeRemoteCommand($server, $portCheckCmd);
    $portOutput = implode("\n", $portCheck['output'] ?? []);

    if (str_contains($portOutput, 'PORT_NOT_LISTENING') || empty(trim($portOutput))) {
        Log::warning("⚠️ {$server->name}: Management port {$mgmtPort} not listening", [
            'port_check_output' => $portOutput
        ]);
    } else {
        Log::info("✅ {$server->name}: Management port {$mgmtPort} is listening", [
            'port_info' => trim($portOutput)
        ]);
    }

    // --- Check OpenVPN process ---
    $processCmd = 'bash -lc ' . escapeshellarg('ps aux | grep openvpn | grep -v grep | head -3');
    $processCheck = $this->executeRemoteCommand($server, $processCmd);
    $processOutput = implode("\n", $processCheck['output'] ?? []);

    if (empty(trim($processOutput))) {
        Log::warning("⚠️ {$server->name}: No OpenVPN processes found");
    } else {
        Log::info("✅ {$server->name}: OpenVPN processes running", [
            'processes' => explode("\n", trim($processOutput))
        ]);
    }

    // --- Management socket with retry logic ---
    $mgmtCommands = [
        '{ printf "status 3\n"; sleep 1; printf "quit\n"; } | nc -w 10 127.0.0.1 ' . $mgmtPort,
        '{ printf "status 3\n"; sleep 2; printf "quit\n"; } | nc -w 15 127.0.0.1 ' . $mgmtPort,
        '{ printf "status 3\n"; sleep 0.5; printf "quit\n"; } | timeout 20 nc 127.0.0.1 ' . $mgmtPort,
    ];

    foreach ($mgmtCommands as $attempt => $mgmtCmdRaw) {
        $mgmtCmd = 'bash -lc ' . escapeshellarg($mgmtCmdRaw);

        Log::info("🔌 {$server->name}: Management connection attempt #" . ($attempt + 1), [
            'command' => $mgmtCmdRaw,
            'port' => $mgmtPort,
            'attempt' => $attempt + 1
        ]);

        $res = $this->executeRemoteCommand($server, $mgmtCmd);
        $out = trim(implode("\n", $res['output'] ?? []));

        Log::info("📊 {$server->name}: Management attempt #" . ($attempt + 1) . " result", [
            'exit_code' => $res['status'] ?? 'unknown',
            'output_length' => strlen($out),
            'contains_client_list' => str_contains($out, "CLIENT_LIST"),
            'contains_mgmt_interface' => str_contains($out, "OpenVPN Management Interface"),
            'first_100_chars' => substr($out, 0, 100)
        ]);

        if (($res['status'] ?? 1) === 0 && $out !== '' &&
            (str_contains($out, "CLIENT_LIST") || str_contains($out, "OpenVPN Management Interface"))) {
            Log::info("📡 {$server->name}: mgmt responded on attempt #" . ($attempt + 1) . " with " . strlen($out) . " bytes");
            return [$out, "mgmt:{$mgmtPort}:attempt" . ($attempt + 1)];
        }

        // Short delay between attempts
        if ($attempt < count($mgmtCommands) - 1) {
            sleep(1);
        }
    }

    // --- Try alternative management commands ---
    Log::info("🔄 {$server->name}: Trying alternative management commands");

    $altCommands = [
        'echo -e "status 3\\nquit\\n" | nc -w 3 127.0.0.1 ' . $mgmtPort,
        'echo -e "status\\nquit\\n" | nc -w 3 127.0.0.1 ' . $mgmtPort,
        '(echo "status 3"; sleep 0.5; echo "quit") | nc -w 5 127.0.0.1 ' . $mgmtPort
    ];

    foreach ($altCommands as $index => $altCmd) {
        $altFullCmd = 'bash -lc ' . escapeshellarg($altCmd);
        $altRes = $this->executeRemoteCommand($server, $altFullCmd);
        $altOut = trim(implode("\n", $altRes['output'] ?? []));

        Log::info("🔄 {$server->name}: Alternative command #{$index}", [
            'command' => $altCmd,
            'exit_code' => $altRes['status'] ?? 'unknown',
            'output_length' => strlen($altOut),
            'contains_client_list' => str_contains($altOut, "CLIENT_LIST")
        ]);

        if (($altRes['status'] ?? 1) === 0 && $altOut !== '' &&
            (str_contains($altOut, "CLIENT_LIST") || str_contains($altOut, "OpenVPN Management Interface"))) {
            Log::info("📡 {$server->name}: Alternative mgmt command worked with " . strlen($altOut) . " bytes");
            return [$altOut, "mgmt_alt:{$mgmtPort}"];
        }
    }

    // --- Fallback to known status files with detailed logging ---
    Log::info("📁 {$server->name}: Attempting status file fallbacks");

    $candidates = array_filter([
        $server->status_log_path ?? null,
        '/run/openvpn/server.status',
        '/run/openvpn/openvpn.status',
        '/run/openvpn/server/server.status',
        '/var/log/openvpn-status.log',
    ]);

    foreach ($candidates as $path) {
        Log::info("📄 {$server->name}: Checking status file: {$path}");

        $cmd = 'bash -lc ' . escapeshellarg(
            "test -s {$path} && cat {$path} || echo '__NOFILE__'"
        );
        $res = $this->executeRemoteCommand($server, $cmd);
        $data = trim(implode("\n", $res['output'] ?? []));

        Log::info("📊 {$server->name}: Status file result for {$path}", [
            'exit_code' => $res['status'] ?? 'unknown',
            'data_length' => strlen($data),
            'is_nofile' => $data === '__NOFILE__',
            'first_100_chars' => substr($data, 0, 100)
        ]);

        if (($res['status'] ?? 1) === 0 && $data !== '' && $data !== '__NOFILE__') {
            Log::info("📄 {$server->name}: using {$path} (" . strlen($data) . " bytes)");
            return [$data, $path];
        }
    }

    Log::error("❌ {$server->name}: All methods failed - no mgmt or status file available", [
        'ssh_working' => ($sshTest['status'] ?? 1) === 0,
        'port_listening' => !str_contains($portOutput, 'PORT_NOT_LISTENING'),
        'openvpn_running' => !empty(trim($processOutput)),
        'mgmt_port' => $mgmtPort,
        'status_paths_checked' => count($candidates)
    ]);

    return ['', 'none'];
}
    /**
     * Push snapshot to the API instead of direct DB/broadcast.
     */
    protected function pushSnapshot(int $serverId, \DateTimeInterface $ts, array $usernames): void
    {
        Log::info('🔊 pushing mgmt.update via API', [
            'server' => $serverId,
            'ts'     => $ts->format(DATE_ATOM),
            'count'  => count($usernames),
            'users'  => $usernames,
        ]);

        try {
            Http::withToken(config('services.panel.token'))
                ->acceptJson()
                ->post(config('services.panel.base') . "/api/servers/{$serverId}/events", [
                    'status' => 'mgmt',
                    'ts'     => $ts->format(DATE_ATOM),
                    'users'  => array_map(fn($u) => ['username' => $u], $usernames),
                ])
                ->throw();
        } catch (\Throwable $e) {
            Log::error("❌ Failed to POST /api/servers/{$serverId}/events: {$e->getMessage()}");
        }
    }
}
