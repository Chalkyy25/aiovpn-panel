<?php

namespace App\Livewire\Pages\Client;

use App\Models\VpnServer;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Dashboard extends Component
{
    public \App\Models\VpnUser $user;
    public \Illuminate\Support\Collection $vpnServers;

    public function mount(): void
    {
        $guard = Auth::guard('client');

        abort_unless($guard->check(), 403);

        /** @var \App\Models\VpnUser $user */
        $user = $guard->user();
        $this->user = $user;

        // Eager-load what we render to avoid N+1s
        $this->vpnServers = $user->vpnServers()
            ->select(['vpn_servers.id','name','location','is_online'])
            ->orderBy('name')
            ->get();
    }

    public function render()
    {
        return view('livewire.pages.client.dashboard', [
            'user'       => $this->user,       // explicit for clarity
            'vpnServers' => $this->vpnServers, // explicit for clarity
        ]);
    }
}