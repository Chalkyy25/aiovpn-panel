<?php

namespace App\Livewire\Pages\Client;

use App\Models\VpnUser;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.client')]
class Dashboard extends Component
{
    public VpnUser $user;
    public Collection $vpnServers;

    public function mount(): void
    {
        $guard = Auth::guard('client');
        abort_unless($guard->check(), 403);

        /** @var VpnUser $user */
        $user = $guard->user();

        $this->user = $user;

        $this->vpnServers = $user->vpnServers()
            ->select(['vpn_servers.id', 'vpn_servers.name', 'vpn_servers.location', 'vpn_servers.is_online'])
            ->orderBy('vpn_servers.name')
            ->get();
    }

    public function render()
    {
        return view('livewire.pages.client.dashboard', [
            'user'       => $this->user,
            'vpnServers' => $this->vpnServers,
        ]);
    }
}