<?php

namespace App\Livewire\Widgets;

use App\Models\VpnServer;
use App\Services\OpenVpnStatusParser;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SSH2;

class ServerBandwidthCard extends Component
{
    public VpnServer $server;

    // Live metrics
    public float $mbps_up = 0.0;       // server → client
    public float $mbps_down = 0.0;     // client → server
    public float $gb_per_hour_up = 0.0;
    public float $projected_tb_month = 0.0;
    public int $active_clients = 0;

    // Controls
    public int $hours_per_day = 3;
    public ?string $error = null;

    public function mount(VpnServer $server, int $hoursPerDay = 3): void
    {
        $this->server = $server;
        $this->hours_per_day = $hoursPerDay;
        $this->sample(); // prime first sample (next poll computes delta)
    }

    public function sample(): void
    {
        $id      = $this->server->id;
        $prevKey = "srv:$id:bw:last";       // ['t','recv','sent']
        $rateKey = "srv:$id:bw:last_rate";  // {'mbps_up','gb_per_hour_up'}
        $this->error = null;

        $prev = Cache::get($prevKey);

        try {
            // ---- Host / port / user (your schema)
            $host = $this->server->ip_address ?? null;
            if (empty($host)) {
                throw new \RuntimeException("No SSH host set for server {$this->server->name}");
            }
            $port = (int)($this->server->ssh_port ?? 22);
            $user = $this->server->ssh_user ?: 'root';

            // ---- SSH
            $ssh = new SSH2($host, $port);
            $ssh->setTimeout(5);

            // ---- AUTH: try KEY first, then PASSWORD (path or inline key supported)
            $loggedIn = false;
            $sshType  = $this->server->ssh_type;   // 'key' | 'password' | null
            $sshKey   = $this->server->ssh_key;    // path OR inline private key text
            $sshPwd   = $this->server->ssh_password ?? '';

            // Try key if type says key OR if any key value exists
            if ($sshType === 'key' || !empty($sshKey)) {
                $keyMaterial = null;

                if (!empty($sshKey)) {
                    if (is_string($sshKey) && is_file($sshKey) && is_readable($sshKey)) {
                        // Treat as filesystem path
                        $keyMaterial = file_get_contents($sshKey);
                    } else {
                        // Treat as inline key content; normalize newlines in case it's escaped
                        $keyMaterial = str_replace(["\\r\\n", "\\n"], "\n", (string)$sshKey);
                        $keyMaterial = preg_replace("/\r\n|\r|\n/", "\n", $keyMaterial);
                    }
                } else {
                    // Last resort: common default path
                    $defaultPath = '/root/.ssh/id_rsa';
                    if (is_readable($defaultPath)) {
                        $keyMaterial = file_get_contents($defaultPath);
                    }
                }

                if (!empty($keyMaterial)) {
                    try {
                        $key = PublicKeyLoader::load($keyMaterial);
                        $loggedIn = $ssh->login($user, $key);
                    } catch (\Throwable $e) {
                        // fall through to password
                    }
                }
            }

            if (!$loggedIn) {
                $loggedIn = $ssh->login($user, $sshPwd);
            }

            if (!$loggedIn) {
                throw new \RuntimeException('SSH login failed: key and password both rejected');
            }

            // ---- Read + parse OpenVPN status (hardcode your known-good path)
            $raw  = OpenVpnStatusParser::fetchRawStatus($ssh, '/var/log/openvpn-status.log');
            $data = OpenVpnStatusParser::parse($raw);

            $now = [
                't'    => $data['updated_at'],
                'recv' => $data['totals']['recv'], // bytes client→server
                'sent' => $data['totals']['sent'], // bytes server→client
            ];
            $this->active_clients = count($data['clients']);

            // Save current totals so next poll can compute deltas
            Cache::put($prevKey, $now, now()->addMinutes(10));

            // Need two samples to compute a rate
            if (!$prev) {
                $this->mbps_up = $this->mbps_down = $this->gb_per_hour_up = $this->projected_tb_month = 0;
                return;
            }

            $dt    = max(1, ($now['t'] ?? 0)   - ($prev['t']   ?? 0));     // seconds
            $dRecv = max(0, ($now['recv'] ?? 0)- ($prev['recv']?? 0));     // bytes
            $dSent = max(0, ($now['sent'] ?? 0)- ($prev['sent']?? 0));     // bytes

            // bytes/sec → bits/sec → Mbps (SI)
            $this->mbps_down = round(($dRecv * 8 / $dt) / 1_000_000, 2);
            $this->mbps_up   = round(($dSent * 8 / $dt) / 1_000_000, 2);

            // GB/hour (upload side is what counts for outbound billing)
            $gb_per_sec_up = ($dSent / (1024 ** 3)) / $dt;
            $this->gb_per_hour_up = round($gb_per_sec_up * 3600, 2);

            // Monthly projection in TB
            $this->projected_tb_month = round(($this->gb_per_hour_up * $this->hours_per_day * 30) / 1024, 2);

            // Publish for fleet totals widget
            Cache::put($rateKey, [
                'mbps_up'        => $this->mbps_up,
                'gb_per_hour_up' => $this->gb_per_hour_up,
            ], now()->addMinutes(10));

        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
            $this->active_clients = 0;
            $this->mbps_up = $this->mbps_down = $this->gb_per_hour_up = $this->projected_tb_month = 0;
        }
    }

    public function render()
    {
        return view('livewire.widgets.server-bandwidth-card');
    }
}