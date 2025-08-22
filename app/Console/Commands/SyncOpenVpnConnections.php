<?php

namespace App\Console\Commands;

use App\Models\VpnServer;
use App\Models\VpnUser;
use App\Models\VpnUserConnection;
use App\Services\OpenVpnStatusParser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use phpseclib3\Net\SSH2;
use phpseclib3\Crypt\PublicKeyLoader;

class SyncOpenVpnConnections extends Command
{
    protected $signature = 'vpn:sync-connections
        {--server= : Server ID or name (repeatable)}
        {--all : Sync all deployed servers}
        {--dry : Dry-run (no DB writes)}
        {--verbose : Extra output}';

    protected $description = 'Ingest OpenVPN status (v2/v3, /run or /var/log), upsert active sessions, and close stale ones.';

    public function handle(): int
    {
        $servers = $this->resolveServers();
        if ($servers->isEmpty()) {
            $this->warn('No servers matched. Use --server="Spain" or --all.');
            return self::SUCCESS;
        }

        $dry = (bool) $this->option('dry');
        $vrb = (bool) $this->option('verbose');

        foreach ($servers as $server) {
            $this->line("→ {$server->name} ({$server->ip_address})");
            try {
                [$ssh] = $this->sshLogin($server);
                if (!$ssh) { $this->error('  SSH login failed.'); continue; }

                // Try /run first, then /var/log (handled inside)
                $raw = OpenVpnStatusParser::fetchAnyStatus($ssh);
                if (trim($raw) === '') { $this->warn('  Status empty.'); continue; }

                $parsed = OpenVpnStatusParser::parse($raw);
                $live   = collect($parsed['clients']);
                if ($vrb) $this->info('  Live: '.($live->pluck('username')->filter()->implode(', ') ?: '—'));

                $userMap = VpnUser::whereIn('username', $live->pluck('username')->filter()->unique())
                    ->pluck('id','username');

                DB::beginTransaction();

                // Upsert live sessions
                foreach ($live as $c) {
                    $username = trim((string)($c['username'] ?? ''));
                    if ($username === '') continue;
                    $vpnUserId = $userMap[$username] ?? null;
                    if (!$vpnUserId) continue;

                    $conn = VpnUserConnection::firstOrNew([
                        'vpn_server_id' => $server->id,
                        'vpn_user_id'   => $vpnUserId,
                        'is_connected'  => true,
                    ]);

                    $conn->client_ip      = $c['client_ip'] ?? null;
                    $conn->virtual_ip     = $c['virtual_ip'] ?? null;
                    $conn->connected_at   = !empty($c['connected_at'])
                        ? date('Y-m-d H:i:s', (int)$c['connected_at'])
                        : ($conn->connected_at ?? now());
                    $conn->bytes_received = (int)($c['bytes_received'] ?? 0);
                    $conn->bytes_sent     = (int)($c['bytes_sent'] ?? 0);
                    $conn->is_connected   = true;
                    $conn->disconnected_at = null;

                    if (!$dry) {
                        $conn->save();
                        VpnUser::where('id', $vpnUserId)->update(['is_online' => true]);
                    }
                }

                // Close stale sessions for this server
                $liveUsernames = $live->pluck('username')->filter()->unique();
                $liveIds = $liveUsernames->isNotEmpty()
                    ? VpnUser::whereIn('username', $liveUsernames)->pluck('id')
                    : collect([]);

                $stales = VpnUserConnection::where('vpn_server_id', $server->id)
                    ->where('is_connected', true)
                    ->when($liveIds->isNotEmpty(), fn($q) => $q->whereNotIn('vpn_user_id', $liveIds))
                    ->when($liveIds->isEmpty(), fn($q) => $q) // if none live, close all connected on this server
                    ->get();

                foreach ($stales as $row) {
                    if (!$dry) {
                        $row->update(['is_connected' => false, 'disconnected_at' => now()]);
                        VpnUserConnection::updateUserOnlineStatusIfNoActiveConnections($row->vpn_user_id);
                    }
                }

                $dry ? DB::rollBack() : DB::commit();
                $this->info('  ✔ synced');

            } catch (\Throwable $e) {
                DB::rollBack();
                $this->error('  Error: '.$e->getMessage());
                Log::error("vpn:sync-connections {$server->name} failed: ".$e->getMessage());
            }
        }

        return self::SUCCESS;
    }

    protected function resolveServers()
    {
        if ($this->option('all')) {
            return VpnServer::where('deployment_status','succeeded')->orderBy('name')->get();
        }

        $terms = (array) $this->option('server');
        $terms = array_filter(array_map('trim', $terms));
        if (!$terms) return collect();

        return VpnServer::where('deployment_status','succeeded')
            ->where(function ($q) use ($terms) {
                foreach ($terms as $t) {
                    $q->orWhere('id', $t)->orWhere('name','like',"%{$t}%");
                }
            })->orderBy('name')->get();
    }

    protected function sshLogin(VpnServer $server): array
    {
        $host = $server->ip_address;
        $port = (int)($server->ssh_port ?: 22);
        $user = $server->ssh_user ?: 'root';

        $ssh = new SSH2($host, $port);
        $ssh->setTimeout(8);

        // key first (path or inline)
        if (!empty($server->ssh_key)) {
            $keyMaterial = is_file($server->ssh_key) ? file_get_contents($server->ssh_key) : (string)$server->ssh_key;
            $keyMaterial = preg_replace("/\r\n|\r|\n/", "\n", $keyMaterial);
            try {
                $key = PublicKeyLoader::load($keyMaterial);
                if ($ssh->login($user, $key)) return [$ssh, $key];
            } catch (\Throwable) {}
        }

        // password fallback
        if (!empty($server->ssh_password) && $ssh->login($user, (string)$server->ssh_password)) {
            return [$ssh, null];
        }

        // project default key
        $default = storage_path('app/ssh_keys/id_rsa');
        if (is_readable($default)) {
            try {
                $key = PublicKeyLoader::load(file_get_contents($default));
                if ($ssh->login($user, $key)) return [$ssh, $key];
            } catch (\Throwable) {}
        }

        return [null, null];
    }
}