<?php

namespace App\Livewire\Pages\Admin;

use App\Models\VpnServer;
use Livewire\Component;
use Livewire\Attributes\Layout;

#[Layout('layouts.app')]
class ServerEdit extends Component
{
    public VpnServer $server;

    public $name, $ip_address, $protocol, $status;

    public function mount(VpnServer $server)
    {
        $this->server = $server;
        $this->name = $server->name;
        $this->ip_address = $server->ip_address;
        $this->protocol = $server->protocol;
        $this->status = $server->status;
    }

    public function save()
    {
        $this->validate([
            'name' => 'required|string|max:255',
            'ip_address' => 'required|ip',
            'protocol' => 'required|in:openvpn,wireguard',
            'status' => 'required|in:online,offline,pending'
        ]);

        $this->server->update([
            'name' => $this->name,
            'ip_address' => $this->ip_address,
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
