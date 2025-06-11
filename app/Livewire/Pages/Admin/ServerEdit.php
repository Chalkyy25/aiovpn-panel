<?php

namespace App\Livewire\Pages\Admin;

use App\Models\VpnServer;
use Livewire\Component;
use Livewire\Attributes\Layout;

#[Layout('layouts.app')]
class ServerEdit extends Component
{
    public VpnServer $server;

    public $name, $ip, $protocol, $status;

    public function mount(VpnServer $server)
    {
        $this->server = $server;
        $this->name = $server->name;
        $this->ip = $server->ip;
        $this->protocol = $server->protocol;
        $this->status = $server->status;
    }

    public function save()
    {
        $this->validate([
            'name' => 'required|string|max:255',
            'ip' => 'required|ip',
            'protocol' => 'required|in:OpenVPN,WireGuard',
            'status' => 'required|in:online,offline'
        ]);

        $this->server->update([
            'name' => $this->name,
            'ip' => $this->ip,
            'protocol' => $this->protocol,
            'status' => $this->status
        ]);

        session()->flash('status-message', 'Server updated successfully.');
        return redirect()->route('admin.servers.index');
    }

    public function render()
    {
        return view('livewire.pages.admin.server-edit');
    }
}
