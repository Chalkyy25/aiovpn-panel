<?php

namespace App\Livewire\Admin;

use Livewire\Attributes\Layout;
use Livewire\Component;
use App\Models\VpnServer;

#[Layout('layouts.app')]
class ServerInstallStatus extends Component
{
    public VpnServer $server;

    public function mount(VpnServer $vpnServer)
    {
        $this->server = $vpnServer;
    }

    public function checkStatus()
    {
        $this->server->refresh();
    }

    public function render()
    {
        return view('livewire.admin.server-install-status');
    }
}
