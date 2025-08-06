<?php

namespace App\Livewire\Pages\Admin;

use Livewire\Component;
use App\Models\VpnServer;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Computed;

#[Layout('layouts.app')]
class ServerInstallStatus extends Component
{
    public VpnServer $vpnServer;
    public string $deploymentLog = '';
    public string $deploymentStatus = '';

    public function mount(VpnServer $vpnserver): void
    {
        $this->vpnServer = $vpnserver;
        $this->refreshStatus();
    }

    public function refreshStatus()
    {
        $this->vpnServer->refresh();
        $this->deploymentLog = $this->vpnServer->deployment_log ?? '';
        $this->deploymentStatus = $this->vpnServer->deployment_status ?? '';
    }

    // Computed property for filtered log lines

    #[Computed]
    public function filteredLog(): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $this->deploymentLog ?? '');

        $filtered = [];
        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '') continue; // Only skip completely blank lines

            // Determine color based on line content
            if (str_contains($line, '❌')) $color = 'text-red-400';
            elseif (str_contains($line, 'WARNING')) $color = 'text-yellow-400';
            elseif (str_contains($line, '✅')) $color = 'text-green-400';
            else $color = 'text-white';

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
