<?php

namespace App\Livewire;

use Livewire\Component;
use App\Models\VpnServer;

class TerminalOutput extends Component
{
    public VpnServer $server;

    public function mount(VpnServer $server)
    {
        $this->server = $server;
    }

public function getLogProperty()
{
    return $this->server->fresh()->deployment_log ?? 'No log output yet.';
}
    public function render()
    {
        return view('livewire.terminal-output', [
            'log' => $this->log,
        ]);
    }
}
