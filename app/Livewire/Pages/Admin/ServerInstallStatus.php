<?php

namespace App\Livewire\Pages\Admin;

use Livewire\Component;
use App\Models\VpnServer;
use Livewire\Attributes\Layout;

#[Layout('layouts.app')]
class ServerInstallStatus extends Component
{
    public VpnServer $vpnServer;
    public string $deploymentLog = '';
    public string $deploymentStatus = '';

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

    // Computed property (Livewire 3) for filtered log lines
    public function getFilteredLogProperty(): array
    {
        $lines = explode("\n", $this->deploymentLog ?? '');

        $filtered = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if (
                $line === '' ||
                preg_match('/^\.+\+|\*+|DH parameters appear to be ok|Generating DH parameters|DEPRECATED OPTION|Reading database|^-----$/', $line)
            ) {
                continue;
            }

            $color = '';
            if (str_contains($line, '❌')) $color = 'text-red-400';
            elseif (str_contains($line, 'WARNING')) $color = 'text-yellow-400';
            elseif (str_contains($line, '✅')) $color = 'text-green-400';

            $filtered[] = [
                'text' => $line,
                'color' => $color,
            ];
        }

        return $filtered;
    }

    public function render()
    {
        return view('livewire.pages.admin.server-install-status');
    }
}