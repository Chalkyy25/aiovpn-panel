<?php

namespace App\Livewire\Pages\Admin;

use Livewire\Component;
use App\Models\VpnServer;
use Livewire\Attributes\Layout;

#[Layout('layouts.app')]
class ServerInstallStatus extends Component
{
    public VpnServer $vpnServer;
    public $deploymentLog = '';
    public $deploymentStatus = '';

    public function mount(VpnServer $vpnServer)
    {
        $this->vpnServer = $vpnServer;
        $this->refreshStatus();
    }

    public function refreshStatus()
    {
        $this->vpnServer->refresh();
        $this->deploymentLog = $this->vpnServer->deployment_log ?? '';
        $this->deploymentStatus = $this->vpnServer->deployment_status ?? '';
    }

    public function render()
    {
        return view('livewire.pages.admin.server-install-status', [
            'vpnServer' => $this->vpnServer,
            'deploymentLog' => $this->deploymentLog,
            'deploymentStatus' => $this->deploymentStatus,
        ]);
    }
}
