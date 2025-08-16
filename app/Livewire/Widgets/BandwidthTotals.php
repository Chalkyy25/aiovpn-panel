<?php

namespace App\Livewire\Widgets;

use App\Models\VpnServer;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;

class BandwidthTotals extends Component
{
    public float $mbps_up = 0;
    public float $projected_tb_month = 0;
    public int $hoursPerDay = 3;

    public function mount(int $hoursPerDay = 3){ $this->hoursPerDay = $hoursPerDay; }

    public function render()
    {
        $this->mbps_up = 0;
        $this->projected_tb_month = 0;

        foreach (VpnServer::all() as $srv) {
            $rate = Cache::get("srv:{$srv->id}:bw:last_rate"); // set by ServerBandwidthCard
            if ($rate) {
                $this->mbps_up += $rate['mbps_up'] ?? 0;
                $gbh = $rate['gb_per_hour_up'] ?? 0;
                $this->projected_tb_month += ($gbh * $this->hoursPerDay * 30) / 1024;
            }
        }

        return view('livewire.widgets.bandwidth-totals');
    }
}