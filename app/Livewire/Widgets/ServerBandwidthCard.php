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

    public float $mbps_up = 0.0;       // server → client
    public float $mbps_down = 0.0;     // client → server
    public float $gb_per_hour_up = 0.0;
    public float $projected_tb_month = 0.0;
    public int $active_clients = 0;
    public int $hours_per_day = 3;
    public ?string $error = null;

    public function mount(VpnServer $server, int $hoursPerDay = 3)
    {
        $this->server = $server;
        $this->hours_per_day = $hoursPerDay;
        $this->sample(); // first read primes the cache
    }

    public function sample(): void
    {
        $id = $this->server->id;
        $prevKey = "srv:$id:bw:last";         // ['t','recv','sent']
        $rateKey = "srv:$id:bw:last_rate";    // published for totals

        $prev = Cache::get($prevKey);
        $this->error = null;

        try {
            // SSH
            $ssh = new SSH2($this->server->ip, (int)($this->server->ssh_port ?? 22));
            if ($this->server->ssh_login_type === 'key') {
                $key = PublicKeyLoader::load(file_get_contents($this->server->ssh_key_path ?: '/root/.ssh/id_rsa'));
                if (!$ssh->login($this->server->ssh_username ?? 'root', $key)) throw new \RuntimeException('SSH key login failed');
            } else {
                if (!$ssh->login($this->server->ssh_username ?? 'root', $this->server->ssh_password ?? '')) throw new \RuntimeException('SSH password login failed');
            }

            // Status read + parse
            $raw  = OpenVpnStatusParser::fetchRawStatus($ssh, $this->server->ovpn_status_path ?: null);
            $data = OpenVpnStatusParser::parse($raw);

            $now = ['t' => $data['updated_at'], 'recv' => $data['totals']['recv'], 'sent' => $data['totals']['sent']];
            $this->active_clients = count($data['clients']);

            // Save current totals for next poll
            Cache::put($prevKey, $now, now()->addMinutes(10));

            // Need two samples to compute a rate
            if (!$prev) return;

            $dt    = max(1, $now['t'] - $prev['t']);                 // seconds
            $dRecv = max(0, $now['recv'] - $prev['recv']);           // bytes
            $dSent = max(0, $now['sent'] - $prev['sent']);           // bytes

            // bytes/sec → bits/sec → Mbps (SI)
            $this->mbps_down = round(($dRecv * 8 / $dt) / 1_000_000, 2);
            $this->mbps_up   = round(($dSent * 8 / $dt) / 1_000_000, 2);

            // GB/hour (upload)
            $gb_per_sec_up = ($dSent / (1024 ** 3)) / $dt;
            $this->gb_per_hour_up = round($gb_per_sec_up * 3600, 2);

            // Monthly projection (TB)
            $this->projected_tb_month = round(($this->gb_per_hour_up * $this->hours_per_day * 30) / 1024, 2);

            // Publish for fleet totals
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

    public function render() { return view('livewire.widgets.server-bandwidth-card'); }
}