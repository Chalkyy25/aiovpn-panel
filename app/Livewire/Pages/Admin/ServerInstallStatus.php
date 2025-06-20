<?php

namespace App\Livewire\Pages\Admin;

use Livewire\Attributes\Layout;
use Livewire\Component;
use App\Models\VpnServer;

#[Layout('layouts.app')]
class ServerInstallStatus extends Component
{
    public VpnServer $vpnServer;

    public function checkStatus()
    {
        $this->vpnServer->refresh();
    }

    public function render()
    {
        return view('livewire.admin.server-install-status');
    }
    public function mount(VpnServer $vpnServer)
    {
        $this->vpnServer = $vpnServer;
    }public function getShouldPollProperty()
{
    return in_array($this->vpnServer->deployment_status, ['deploying']);
}
    public function getPollingIntervalProperty()
    {
        return 5000; // Poll every 5 seconds
    }
    public function getPollingMethodProperty()
    {
        return 'checkStatus';
    }

}
