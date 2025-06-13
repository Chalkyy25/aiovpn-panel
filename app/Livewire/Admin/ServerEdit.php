<?php

namespace App\Livewire\Admin;

use App\Models\VpnServer;
use Livewire\Component;

class ServerEdit extends Component
{
    public VpnServer $vpnServer;

    public string $name;
    public string $ip_address; // Changed this line
    public string $protocol;
    public string $status;

    public function mount(VpnServer $vpnServer): void
    {
        $this->vpnServer = $vpnServer;
        $this->name = $vpnServer->name;
        $this->ip_address = $vpnServer->ip_address; // Used ip_address
        $this->protocol = $vpnServer->protocol;
        $this->status = $vpnServer->status;
    }

    public function save(): void
    {
        $this->validate([
            'name' => 'required|string',
            'ip_address' => 'required|ip',
            'protocol' => 'required|string',
            'status' => 'required|string',
        ]);

        $this->vpnServer->update([
            'name' => $this->name,
            'ip_address' => $this->ip_address,
            'protocol' => $this->protocol,
            'status' => $this->status,
        ]);

        session()->flash('success', 'VPN server updated successfully.');
    }

    public function render()
    {
        return view('livewire.admin.server-edit');
    }
}
