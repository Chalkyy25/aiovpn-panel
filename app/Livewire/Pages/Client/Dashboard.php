<?php

namespace App\Livewire\Pages\Client;

use App\Models\VpnUser;
use App\Models\VpnServer;
use Livewire\Component;
use Livewire\Attributes\Layout;
use Illuminate\Support\Facades\Auth;

#[Layout('layouts.app')]
class Dashboard extends Component
{
    public $vpnUser;     // the logged-in client
    public $vpnServers;  // servers assigned to this client

    public function mount(): void
    {
        $this->vpnUser    = Auth::guard('client')->user();
        $this->vpnServers = $this->vpnUser?->vpnServers()->get() ?? collect();
    }

    public function render()
    {
        return view('livewire.pages.client.dashboard', [
            'vpnUser'    => $this->vpnUser,
            'vpnServers' => $this->vpnServers,
        ]);
    }
}