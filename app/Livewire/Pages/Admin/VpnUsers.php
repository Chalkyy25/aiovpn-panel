<?php

namespace App\Livewire\Pages\Admin;

use Livewire\Component;
use App\Models\VpnUser;
use Livewire\Attributes\Layout;

#[Layout('layouts.app')]
class VpnUsers extends Component
{
    public $users;

    public function mount()
    {
        // 🔧 Load all VPN users with their assigned servers
        $this->users = VpnUser::with('vpnServer')->get();
    }

    public function render()
    {
        return view('livewire.pages.admin.vpn-users')
            ->title('VPN Test Users')
            ->with(['users' => $this->users]);
    }
}
