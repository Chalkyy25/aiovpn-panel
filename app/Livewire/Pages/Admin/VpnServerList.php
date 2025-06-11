<?php

namespace App\Livewire\Pages\Admin;

use Livewire\Component;
use Livewire\Attributes\Layout;
use App\Models\VpnServer;

#[Layout('layouts.app')]
class VpnServerList extends Component
{
    public $servers;
    public $showAddModal = false;

    public $name, $ip, $protocol = 'OpenVPN', $status = 'offline';

    public function mount()
    {
        $this->loadServers();
    }

    public function loadServers()
    {
        $this->servers = VpnServer::latest()->get();
    }

    public function createServer()
    {
        $this->validate([
            'name' => 'required',
            'ip' => 'required|ip',
            'protocol' => 'required',
        ]);

        VpnServer::create([
            'name' => $this->name,
            'ip' => $this->ip,
            'protocol' => $this->protocol,
            'status' => $this->status,
        ]);

        $this->reset(['name', 'ip', 'protocol', 'status', 'showAddModal']);
        $this->loadServers();

        session()->flash('status-message', '✅ VPN Server added successfully.');
    }

    public function render()
    {
        return view('livewire.pages.admin.vpn-server-list');
    }
}
