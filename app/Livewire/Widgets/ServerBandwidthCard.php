<?php

namespace App\Livewire\Widgets;

use App\Models\VpnServer;
use App\Services\OpenVpnStatusParser;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Livewire\Component;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SSH2;

class ServerBandwidthCard extends Component
{
    public VpnServer $server;

    // Live metrics
    public float $mbps_up = 0.0;        // server → client (bytes sent by server)
    public float $mbps_down = 0.0;      // client → server (bytes received by server)
    public float $gb_per_hour_up = 0.0;
    public float $projected_tb_month = 0.0;
    public int $active_clients = 0;

    // Controls
    public int $hours_per_day = 3;
    public ?string $error = null;

    /** Default private key path we standardised on */
    private const DEFAULT_KEY_REL = 'app/ssh_keys/id_rsa';

    public function mount(VpnServer $server, int $hoursPerDay = 3): void
    {
        $this->server = $server;
        $this->hours_per_day = $hoursPerDay;

        // Prime one sample so the next tick can compute a delta
        $this->sample();
    }

    public function sample(): void
    {
        $id      = $this->server->id;
        $prevKey = "srv:$id:bw:last";        // ['t','recv','sent']
        $rateKey = "srv:$id:bw:last_rate";   // {'mbps_up','gb_per_hour_up'}
        $this->error = null;

        $prev = Cache::get($prevKey);

        try {
            // ---------------- SSH connection details ----------------
            $host = trim((string) ($this->server->ip_address ?? ''));
            if ($host === '') {
                throw new \RuntimeException("No SSH host set for server '{$this->server->name}'.");
            }
            $port = (int) ($this->server->ssh_port ?: 22);
            $user = $this->server->ssh_user ?: 'root';

            $ssh = new SSH2($host, $port);
            $ssh->setTimeout(8);

            // ---------------- Auth: prefer KEY, fallback PASSWORD ----------------
            $loggedIn = false;

            // 1) Try a key from the DB (inline or absolute path)
            $sshType = $this->server->ssh_type;       // 'key' | 'password' | null
            $sshKey  = $this->server->ssh_key;        // may be inline PEM or a path

            // Normalise a usable private key blob
            $keyMaterial = null;

            if ($sshType === 'key' || !empty($sshKey)) {
                if (is_string($sshKey)) {
                    // If it looks like a path and exists, read it; otherwise treat as inline
                    if (is_file($sshKey) && is_readable($sshKey)) {
                        $keyMaterial = file_get_contents($sshKey);
                    } else {
                        // Inline PEM, ensure real newlines
                        $keyMaterial = preg_replace("/\r\n|\r|\n/", "\n", str_replace(["\\r\\n", "\\n"], "\n", $sshKey));
                    }
                }
            }

            // 2) If we still don't have a key, use our standard storage path
            if (empty($keyMaterial)) {
                $defaultKey = storage_path(self::DEFAULT_KEY_REL); // /var/www/aiovpn/storage/app/ssh_keys/id_rsa
                if (!is_file($defaultKey) || !is_readable($defaultKey)) {
                    throw new \RuntimeException("SSH key not found/readable at {$defaultKey}. Fix perms: chown www-data:www-data && chmod 600.");
                }
                $keyMaterial = file_get_contents($defaultKey);
            }

            // Attempt key login
            try {
                $pk = PublicKeyLoader::load($keyMaterial);
                $loggedIn = $ssh->login($user, $pk);
            } catch (\Throwable $e) {
                // Continue to password fallback below
                Log::warning("SSH key load/login failed for {$this->server->name}: {$e->getMessage()}");
            }

            // Fallback to password if configured
            if (!$loggedIn) {
                $pwd = (string) ($this->server->ssh_password ?? '');
                if ($pwd !== '') {
                    $loggedIn = $ssh->login($user, $pwd);
                }
            }

            if (!$loggedIn) {
                throw new \RuntimeException('SSH login failed (key and password were rejected).');
            }

            // ---------------- Fetch + parse OpenVPN status ----------------
            $raw  = OpenVpnStatusParser::fetchRawStatus($ssh, '/run/openvpn/server.status');
            $data = OpenVpnStatusParser::parse($raw);

            // Expect:
            // $data['updated_at'] (int|Carbon|string)    epoch or parseable time
            // $data['totals']['recv'] (int bytes)        client->server
            // $data['totals']['sent'] (int bytes)        server->client
            // $data['clients'] (array)
            $t = $data['updated_at'] ?? null;
            $t = is_numeric($t) ? (int) $t : (is_object($t) && method_exists($t, 'getTimestamp') ? $t->getTimestamp() : strtotime((string) $t));
            if (!$t) {
                $t = time();
            }

            $recv = (int) ($data['totals']['recv'] ?? 0);
            $sent = (int) ($data['totals']['sent'] ?? 0);

            $now = ['t' => $t, 'recv' => $recv, 'sent' => $sent];
            $this->active_clients = is_array($data['clients'] ?? null) ? count($data['clients']) : 0;

            // Save current sample for next delta
            Cache::put($prevKey, $now, now()->addMinutes(10));

            // Need at least 2 samples
            if (!$prev || empty($prev['t'])) {
                $this->mbps_up = $this->mbps_down = $this->gb_per_hour_up = $this->projected_tb_month = 0.0;
                return;
            }

            // ---------------- Compute rates ----------------
            $dt    = max(1, (int) $now['t']   - (int) $prev['t']);    // seconds
            $dRecv = max(0, (int) $now['recv']- (int) $prev['recv']); // bytes
            $dSent = max(0, (int) $now['sent']- (int) $prev['sent']); // bytes

            // bytes/sec → bits/sec → Mbps
            $this->mbps_down = round(($dRecv * 8 / $dt) / 1_000_000, 2);
            $this->mbps_up   = round(($dSent * 8 / $dt) / 1_000_000, 2);

            // GB/hour on upload (server->client)
            $gb_per_sec_up = ($dSent / (1024 ** 3)) / $dt;
            $this->gb_per_hour_up = round($gb_per_sec_up * 3600, 2);

            // 30-day projection with configurable daily hours → TB/month
            $this->projected_tb_month = round(($this->gb_per_hour_up * $this->hours_per_day * 30) / 1024, 2);

            // Publish for any fleet-total widget
            Cache::put($rateKey, [
                'mbps_up'        => $this->mbps_up,
                'gb_per_hour_up' => $this->gb_per_hour_up,
            ], now()->addMinutes(10));

        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
            Log::error("ServerBandwidthCard error for server {$this->server->id} ({$this->server->name}): ".$e->getMessage());
            $this->active_clients = 0;
            $this->mbps_up = $this->mbps_down = $this->gb_per_hour_up = $this->projected_tb_month = 0.0;
        }
    }

    public function render()
    {
        return view('livewire.widgets.server-bandwidth-card');
    }
}