<?php

namespace App\Livewire\Pages\Client;

use Livewire\Component;
use Livewire\Attributes\Layout;
use Illuminate\Support\Facades\Auth;

#[Layout('layouts.app')]
class Dashboard extends Component
{
    public $user;
    public $vpnServers;

    public function mount(): void
    {
        $this->user = Auth::guard('client')->user();
        $this->vpnServers = $this->user?->vpnServers()->get() ?? collect();
    }

    public function render()
    {
        return view('livewire.pages.client.dashboard', [
            'user' => $this->user,              // <-- IMPORTANT
            'vpnServers' => $this->vpnServers,  // <-- IMPORTANT
        ]);
    }
}