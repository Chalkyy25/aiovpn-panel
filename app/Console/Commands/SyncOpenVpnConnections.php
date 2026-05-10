<?php

namespace App\Console\Commands;

use App\Models\VpnConnection;
use App\Models\VpnServer;
use App\Models\VpnUser;
use App\Services\OpenVpnStatusParser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SSH2;

class SyncOpenVpnConnections extends Command
{
    protected $signature = 'vpn:sync-connections
        {--server= : Server ID or name (repeatable)}
        {--all : Sync all deployed servers}
        {--dry : Dry-run (no DB writes)}';

    protected $description = 'Ingest OpenVPN status, upsert active sessions, and close stale ones.';

    public function handle(): int
    {
        $servers = $this->resolveServers();

        if ($servers->isEmpty()) {
            $this->warn('No servers matched. Use --server="Spain" or --all.');
            return self::SUCCESS;
        }

        $dry = (bool) $this->option('dry');
        $verbose = $this->output->isVerbose();

        foreach ($servers as $server) {
            $this->line("→ {$server->name} ({$server->ip_address})");

            try {
                [$ssh] = $this->sshLogin($server);

                if (! $ssh) {
                    $this->error('  SSH login failed.');
                    continue;
                }

                $statusPaths = [
                    '/run/openvpn/server.status',
                    '/var/log/openvpn/status.log',
                    '/etc/openvpn/openvpn-status.log',
                    '/var/log/openvpn-status.log',
                ];
        
                $raw = '';
                
                foreach ($statusPaths as $path) {
                    $cmd = 'test -f ' . escapeshellarg($path) .
                        ' && cat ' . escapeshellarg($path) . ' 2>/dev/null || true';
                
                    $output = $ssh->exec($cmd);
                
                    if (is_string($output) && trim($output) !== '') {
                        $raw = $output;
                        break;
                    }
                }

                if (trim($raw) === '') {
                    $this->warn('  Status empty.');
                    continue;
                }

                $parsed = OpenVpnStatusParser::parse($raw);

                $live = collect($parsed['clients'] ?? []);

                if ($verbose) {
                    $this->info(
                        '  Live: ' .
                        ($live->pluck('username')->filter()->implode(', ') ?: '—')
                    );
                }

                $userMap = VpnUser::whereIn(
                    'username',
                    $live->pluck('username')->filter()->unique()
                )->pluck('id', 'username');

                DB::beginTransaction();

                foreach ($live as $client) {
                    $username = trim((string) ($client['username'] ?? ''));

                    if ($username === '') {
                        continue;
                    }

                    $vpnUserId = $userMap[$username] ?? null;

                    if (! $vpnUserId) {
                        continue;
                    }

                    $sessionKey = $client['session_key']
                        ?? md5($server->id . ':' . $vpnUserId . ':' . $username);

                    $connection = VpnConnection::firstOrNew([
                        'vpn_server_id' => $server->id,
                        'vpn_user_id'   => $vpnUserId,
                        'protocol'      => 'OPENVPN',
                        'session_key'   => $sessionKey,
                    ]);

                    $connection->client_ip = $client['client_ip'] ?? null;
                    $connection->virtual_ip = $client['virtual_ip'] ?? null;

                    $connection->connected_at = ! empty($client['connected_at'])
                        ? date('Y-m-d H:i:s', (int) $client['connected_at'])
                        : ($connection->connected_at ?? now());

                    $connection->bytes_in = (int) ($client['bytes_received'] ?? 0);
                    $connection->bytes_out = (int) ($client['bytes_sent'] ?? 0);

                    $connection->is_active = true;
                    $connection->last_seen_at = now();
                    $connection->disconnected_at = null;

                    if (! $dry) {
                        $connection->save();
                    }
                }

                $liveUsernames = $live
                    ->pluck('username')
                    ->filter()
                    ->unique();

                $liveIds = $liveUsernames->isNotEmpty()
                    ? VpnUser::whereIn('username', $liveUsernames)->pluck('id')
                    : collect([]);

                $stales = VpnConnection::where('vpn_server_id', $server->id)
                    ->where('protocol', 'OPENVPN')
                    ->where('is_active', true)
                    ->when(
                        $liveIds->isNotEmpty(),
                        fn ($q) => $q->whereNotIn('vpn_user_id', $liveIds)
                    )
                    ->get();

                foreach ($stales as $row) {
                    if (! $dry) {
                        $row->update([
                            'is_active' => false,
                            'last_seen_at' => now(),
                            'disconnected_at' => now(),
                        ]);
                    }
                }

                $dry
                    ? DB::rollBack()
                    : DB::commit();

                $this->info('  ✔ synced');

            } catch (\Throwable $e) {
                DB::rollBack();

                $this->error('  Error: ' . $e->getMessage());

                Log::error(
                    "vpn:sync-connections {$server->name} failed: {$e->getMessage()}"
                );
            }
        }

        return self::SUCCESS;
    }

    protected function resolveServers()
    {
        if ($this->option('all')) {
            return VpnServer::where('deployment_status', 'success')
                ->orderBy('name')
                ->get();
        }

        $terms = array_filter(
            array_map('trim', (array) $this->option('server'))
        );

        if (! $terms) {
            return collect();
        }

        return VpnServer::where('deployment_status', 'success')
            ->where(function ($query) use ($terms) {
                foreach ($terms as $term) {
                    $query
                        ->orWhere('id', $term)
                        ->orWhere('name', 'like', "%{$term}%");
                }
            })
            ->orderBy('name')
            ->get();
    }

    protected function sshLogin(VpnServer $server): array
    {
        $host = $server->ip_address;
        $port = (int) ($server->ssh_port ?: 22);
        $user = $server->ssh_user ?: 'root';

        $ssh = new SSH2($host, $port);
        $ssh->setTimeout(8);

        // Explicit server key
        if (! empty($server->ssh_key)) {
            try {
                $keyMaterial = is_file($server->ssh_key)
                    ? file_get_contents($server->ssh_key)
                    : (string) $server->ssh_key;

                $keyMaterial = preg_replace("/\r\n|\r|\n/", "\n", $keyMaterial);

                $key = PublicKeyLoader::load($keyMaterial);

                if ($ssh->login($user, $key)) {
                    return [$ssh, $key];
                }
            } catch (\Throwable) {
            }
        }

        // Password auth fallback
        if (
            ! empty($server->ssh_password)
            && $ssh->login($user, (string) $server->ssh_password)
        ) {
            return [$ssh, null];
        }

        // Default deployment key fallback
        $defaultKey = storage_path('app/ssh_keys/id_rsa');

        if (is_readable($defaultKey)) {
            try {
                $key = PublicKeyLoader::load(
                    file_get_contents($defaultKey)
                );

                if ($ssh->login($user, $key)) {
                    return [$ssh, $key];
                }
            } catch (\Throwable) {
            }
        }

        return [null, null];
    }
}