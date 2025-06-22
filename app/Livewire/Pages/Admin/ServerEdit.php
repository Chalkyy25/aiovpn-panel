<?php

namespace App\Livewire\Pages\Admin;

use App\Models\VpnServer;
use Livewire\Component;
use Livewire\Attributes\Layout;

#[Layout('layouts.app')]
class ServerEdit extends Component
{
    public VpnServer $vpnServer;

    public $name, $ip_address, $protocol, $status;

    public function mount(VpnServer $vpnServer)
    {
        $this->vpnServer = $vpnServer;
        $this->name = $vpnServer->name;
        $this->ip_address = $vpnServer->ip_address;
        $this->protocol = $vpnServer->protocol;
        $this->status = $vpnServer->status;
    }

    public function save()
    {
        $this->validate([
            'name' => 'required|string|max:255',
            'ip_address' => 'required|ip',
            'protocol' => 'required|in:openvpn,wireguard',
            'status' => 'required|in:online,offline,pending'
        ]);

        $this->vpnServer->update([
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
