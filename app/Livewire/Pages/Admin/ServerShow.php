<?php

namespace App\Livewire\Pages\Admin;

use Livewire\Component;
use App\Models\VpnServer;
use Livewire\Attributes\Layout;

#[Layout('layouts.app')]
class ServerShow extends Component
{
    public VpnServer $server;

    public function mount(VpnServer $server)
    {
        $this->server = $server;
    }

    public function render()
    {
        return view('livewire.pages.admin.server-show');
    }
}
