<?php

namespace App\Livewire\Filament;

use App\Models\VpnServer;
use Livewire\Component;

class ServerDeploymentLog extends Component
{
    public int $serverId;

    public function mount(int $serverId): void
    {
        $this->serverId = $serverId;
    }

    public function render()
    {
        $server = VpnServer::query()->find($this->serverId);

        return view('livewire.filament.server-deployment-log', [
            'server' => $server,
            'log' => (string) ($server?->deployment_log ?? ''),
        ]);
    }
}
