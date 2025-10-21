<?php

namespace App\Console\Commands;

use App\Models\VpnServer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class WireGuardBackfillServers extends Command
{
    protected $signature = 'wg:backfill-servers {--server=}';
    protected $description = 'Discover wg_public_key, wg_endpoint_host, wg_port, wg_subnet from each server and store in DB';

    public function handle(): int
    {
        $query = VpnServer::query();
        if ($id = $this->option('server')) {
            $query->where(is_numeric($id) ? 'id' : 'name', $id);
        }

        $servers = $query->get();
        if ($servers->isEmpty()) {
            $this->warn('No servers found.');
            return self::SUCCESS;
        }

        foreach ($servers as $srv) {
            try {
                $this->info("🔎 {$srv->name} ({$srv->ip_address})");

                // read server public key
                $pub = $this->runRemote($srv, 'cat /etc/wireguard/server_public_key 2>/dev/null || true');

                // endpoint host (public IPv4)
                $endpoint = trim($this->runRemote($srv,
                    "curl -4s https://api.ipify.org || curl -4s https://ifconfig.co || hostname -I | awk '{print \$1}'"
                ));

                // port and address (subnet) from wg0.conf (fallback to defaults)
                $conf = $this->runRemote($srv, 'cat /etc/wireguard/wg0.conf 2>/dev/null || true');
                $wgPort = (int) (preg_match('/^ListenPort\s*=\s*(\d+)/mi', $conf, $m) ? $m[1] : 51820);
                $addr   = preg_match('/^Address\s*=\s*([^\r\n]+)/mi', $conf, $m) ? trim($m[1]) : '10.66.66.1/24';
                // convert server address to subnet (keep /24 from that line)
                $mask   = strpos($addr, '/') !== false ? explode('/', $addr)[1] : '24';
                $octets = explode('.', explode('/', $addr)[0]); $octets[3] = '0';
                $subnet = implode('.', $octets).'/'.$mask;

                // persist
                $srv->wg_public_key    = trim($pub) ?: $srv->wg_public_key;
                $srv->wg_endpoint_host = $endpoint ?: $srv->wg_endpoint_host;
                $srv->wg_port          = $wgPort ?: ($srv->wg_port ?: 51820);
                $srv->wg_subnet        = $subnet ?: ($srv->wg_subnet ?: '10.66.66.0/24');
                $srv->save();

                $this->line("   ↳ public_key: ".substr((string) $srv->wg_public_key, 0, 10).'…');
                $this->line("   ↳ endpoint : {$srv->wg_endpoint_host}:{$srv->wg_port}");
                $this->line("   ↳ subnet   : {$srv->wg_subnet}");

            } catch (\Throwable $e) {
                Log::error("Backfill failed for {$srv->name}: ".$e->getMessage());
                $this->error("❌ Failed on {$srv->name} — see laravel.log");
            }
        }

        $this->info('✅ Backfill complete.');
        return self::SUCCESS;
    }

    private function runRemote(VpnServer $server, string $cmd): string
    {
        // Try your trait’s (server, cmd) signature first; fall back to (host, cmd)
        try {
            $res = app(\App\Traits\ExecutesRemoteCommands::class)
                ? $server->executeRemoteCommand($server, "bash -lc ".escapeshellarg($cmd))
                : ['status'=>1,'output'=>[]];
        } catch (\Throwable $e) {
            $res = $server->executeRemoteCommand((string) $server->ip_address, "bash -lc ".escapeshellarg($cmd));
        }

        if (($res['status'] ?? 1) !== 0) return '';
        return trim(implode("\n", $res['output'] ?? []));
    }
}