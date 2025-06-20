<?php

namespace App\Livewire\Pages\Admin;

use Livewire\Component;
use App\Models\VpnServer;
use Livewire\Attributes\Layout;

#[Layout('layouts.app')]
class ServerInstallStatus extends Component
{
    public VpnServer $server;
    public $deploymentLog = '';
    public $deploymentStatus = '';

    public function mount(VpnServer $vpnServer)
    {
        $this->server = $vpnServer;
        $this->refreshStatus();
    }

    public function refreshStatus()
    {
        $this->server->refresh();
        $this->deploymentLog = $this->server->deployment_log ?? '';
        $this->deploymentStatus = $this->server->deployment_status ?? '';
    }

    public function render()
    {
        return view('livewire.pages.admin.server-install-status');
    }
}
