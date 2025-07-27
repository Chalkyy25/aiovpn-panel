<?php

namespace App\Livewire\Pages\Admin;

use Illuminate\Contracts\View\View;
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

    protected $listeners = ['refreshOnlineCounts' => '$refresh'];

    public function pollOnlineCounts(): void
    {
        $this->loadServers(); // reload data from DB (and logs)
    }

    public function syncServer($id): void
    {
        $server = VpnServer::find($id);

        if (!$server) {
            session()->flash('status-message', '❌ Server not found.');
            return;
        }

        dispatch(new SyncOpenVPNCredentials($server));

        session()->flash('status-message', "🔄 Sync started for $server->name");
    }
    public function mount(): void
    {
        $this->loadServers();
    }

    public function loadServers(): void
    {
        $this->servers = VpnServer::latest()->get();
    }

    public function createServer(): void
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

    public function deleteServer($id): void
    {
    VpnServer::destroy($id);
    $this->loadServers();
    session()->flash('status-message', '🗑️ VPN Server deleted.');
}
    public function render(): View
    {
        return view('livewire.pages.admin.vpn-server-list');
    }

}
