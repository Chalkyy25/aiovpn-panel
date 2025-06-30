<?php

namespace App\Livewire\Pages\Admin;

use Livewire\Component;
use App\Models\VpnUser;

class VpnUsers extends Component
{
    public $users;

    public function mount()
    {
        $this->users = VpnUser::with('vpnServer')->get();
    }

    public function render()
    {
        return view('livewire.pages.admin.vpn-users');
    }
}
