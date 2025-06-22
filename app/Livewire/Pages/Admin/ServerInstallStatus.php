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

    public function getFilteredLogProperty()
    {
        $lines = explode("\n", $this->deploymentLog ?? '');
        $filtered = [];
        foreach ($lines as $line) {
            if (
                preg_match('/^\.+\+|\*+|DH parameters appear to be ok|Generating DH parameters|DEPRECATED OPTION/', $line)
                || trim($line) === ''
            ) {
                continue;
            }
            $color = '';
            if (str_contains($line, '❌')) $color = 'text-red-400';
            elseif (str_contains($line, 'WARNING')) $color = 'text-yellow-400';
            elseif (str_contains($line, '✅')) $color = 'text-green-400';
            $filtered[] = ['text' => $line, 'color' => $color];
        }
        return $filtered;
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
