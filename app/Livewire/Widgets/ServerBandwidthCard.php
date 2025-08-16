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
            // ---- Resolve SSH host (uses ip_address, falls back to ip/hostname)
            $host = $this->server->ip_address
                ?: ($this->server->ip ?? null)
                ?: ($this->server->hostname ?? null);

            if (empty($host)) {
                throw new \RuntimeException("No SSH host set for server {$this->server->name}");
            }

            $ssh = new SSH2($host, (int)($this->server->ssh_port ?? 22));
            $ssh->setTimeout(5);

            if ($this->server->ssh_login_type === 'key') {
                $keyPath = $this->server->ssh_key_path ?: '/root/.ssh/id_rsa';
                if (!is_readable($keyPath)) {
                    throw new \RuntimeException("SSH key not readable at {$keyPath}");
                }
                $key = PublicKeyLoader::load(file_get_contents($keyPath));
                if (!$ssh->login($this->server->ssh_username ?? 'root', $key)) {
                    throw new \RuntimeException('SSH key login failed');
                }
            } else {
                if (!$ssh->login($this->server->ssh_username ?? 'root', $this->server->ssh_password ?? '')) {
                    throw new \RuntimeException('SSH password login failed');
                }
            }

            // ---- Read + parse OpenVPN status (auto-detects path; supports v2 headers)
            $raw  = OpenVpnStatusParser::fetchRawStatus($ssh, $this->server->ovpn_status_path ?: null);
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

            $dt    = max(1, $now['t']   - ($prev['t']   ?? $now['t']));     // seconds
            $dRecv = max(0, $now['recv']- ($prev['recv']?? $now['recv']));  // bytes
            $dSent = max(0, $now['sent']- ($prev['sent']?? $now['sent']));  // bytes

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