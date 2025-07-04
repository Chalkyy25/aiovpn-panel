<?php
namespace App\Livewire\Pages\Admin;

use Livewire\Component;
use Livewire\Attributes\Layout;
use App\Models\VpnUser;

#[Layout('layouts.app')]
class VpnUserConfigs extends Component
{
    public VpnUser $vpnUser;

    public function mount(VpnUser $vpnUser)
    {
        $this->vpnUser = $vpnUser->load('vpnServers');
    }

    public function render()
    {
        return view('livewire.pages.admin.vpn-user-configs');
    }
}
