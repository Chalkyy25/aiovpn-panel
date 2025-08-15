<?php

namespace App\Livewire\Widgets;

use App\Models\VpnServer;
use App\Services\OpenVpnStatusParser;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\On;
use Livewire\Component;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SSH2;

class ServerBandwidthCard extends Component
{
    public VpnServer $server;
    public array $now = [];
    public ?array $prev = null;

    public float $mbps_up = 0.0;
    public float $mbps_down = 0.0;
    public float $gb_per_hour_up = 0.0;
    public float $projected_tb_month = 0.0;

    public int $active_clients = 0;

    // CONFIG: assumed viewing hours per day (for projection)
    public int $hours_per_day = 3;

    public function mount(VpnServer $server, int $hoursPerDay = 3)
    {
        $this->server = $server;
        $this->hours_per_day = $hoursPerDay;
        $this->sample();
    }

    #[On('refreshServerStats')]
    public function sample()
    {
        $cacheKey = "srv:{$this->server->id}:bw:last";
        $this->prev = Cache::get($cacheKey);

        $ssh = new SSH2($this->server->ip, (int)($this->server->ssh_port ?? 22));
        if ($this->server->ssh_login_type === 'key') {
            $key = PublicKeyLoader::load(file_get_contents($this->server->ssh_key_path));
            $ssh->login($this->server->ssh_username ?? 'root', $key);
        } else {
            $ssh->login($this->server->ssh_username ?? 'root', $this->server->ssh_password);
        }

        $raw  = OpenVpnStatusParser::fetchRawStatus($ssh, $this->server->ovpn_status_path ?? '/etc/openvpn/openvpn-status.log');
        $data = OpenVpnStatusParser::parse($raw);

        $this->now = [
            't'     => $data['updated_at'],
            'recv'  => $data['totals']['recv'],
            'sent'  => $data['totals']['sent'],
        ];
        $this->active_clients = count($data['clients']);

        // Persist so the next poll can delta this
        Cache::put($cacheKey, $this->now, now()->addMinutes(10));

        if ($this->prev) {
            $dt = max(1, $this->now['t'] - $this->prev['t']); // seconds
            $dRecv = max(0, $this->now['recv'] - $this->prev['recv']); // bytes
            $dSent = max(0, $this->now['sent'] - $this->prev['sent']); // bytes

            // bytes/sec → bits/sec → Mbps
            $this->mbps_down = round(($dRecv * 8 / $dt) / 1_000_000, 2);
            $this->mbps_up   = round(($dSent * 8 / $dt) / 1_000_000, 2);

            // GB per hour (upload focus, what you pay for)
            $gb_per_sec_up = ($dSent / (1024**3)) / $dt;
            $this->gb_per_hour_up = round($gb_per_sec_up * 3600, 2);

            // Monthly TB projection: GB/hr × hours/day × 30 / 1024
            $tb = ($this->gb_per_hour_up * $this->hours_per_day * 30) / 1024;
            $this->projected_tb_month = round($tb, 2);
        }
    }

    public function render()
    {
        return view('livewire.widgets.server-bandwidth-card');
    }
}