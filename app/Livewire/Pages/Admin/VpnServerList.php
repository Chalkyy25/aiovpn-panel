<?php

namespace App\Livewire\Pages\Admin;

use Livewire\Component;
use Livewire\Attributes\Layout;
use App\Models\VpnServer;
use App\Jobs\SyncOpenVPNCredentials;

#[Layout('layouts.app')]
class VpnServerList extends Component
{
    public $servers;
    public $showAddModal = false;

    public $name, $ip, $protocol = 'OpenVPN', $status = 'offline';

    public $deployLog = [];
    public $isDeploying = false;

    public function syncServer($id): void
    {
        $server = VpnServer::find($id);

        if (!$server) {
            session()->flash('status-message', '❌ Server not found.');
            return;
        }

        dispatch(new SyncOpenVPNCredentials($server));

        session()->flash('status-message', "🔄 Sync started for {$server->name}");
    }
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
            'ip_address' => $this->ip, // use ip_address if that's your DB column
            'protocol' => $this->protocol,
            'status' => $this->status,
        ]);

        $this->reset(['name', 'ip', 'protocol', 'status', 'showAddModal']);
        $this->loadServers();

        session()->flash('status-message', '✅ VPN Server added successfully.');
    }

    public function deleteServer($id)
{
    VpnServer::destroy($id);
    $this->loadServers();
    session()->flash('status-message', '🗑️ VPN Server deleted.');
}
    public function render()
    {
        return view('livewire.pages.admin.vpn-server-list');
    }
}
