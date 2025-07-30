<?php

namespace App\Livewire\Pages\Admin;

use Exception;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\Attributes\Layout;
use App\Models\VpnServer;
use App\Jobs\SyncOpenVPNCredentials;
use Log;

#[Layout('layouts.app')]
class VpnServerList extends Component
{
    public $servers;
    public $showAddModal = false;

    public $name, $ip, $protocol = 'OpenVPN', $status = 'offline';

    public $deployLog = [];
    public $isDeploying = false;
    // ... existing properties ...
    public $syncStatus = [];

    protected $listeners = ['refreshOnlineCounts' => '$refresh'];

    public function pollOnlineCounts(): void
    {
        $this->servers = VpnServer::all()->map(function ($server) {
            $server->online_user_count = $server->getOnlineUserCount();
            return $server;
        });
    }




    public function syncServer($id): void
    {
        $server = VpnServer::find($id);

        if (!$server) {
            session()->flash('status-message', '❌ Server not found.');
            return;
        }

        try {
            dispatch(new SyncOpenVPNCredentials($server));
            $this->syncStatus[$id] = 'syncing';
            session()->flash('status-message', "🔄 Sync started for $server->name");
        } catch (Exception $e) {
            $this->syncStatus[$id] = 'failed';
            session()->flash('status-message', "❌ Sync failed for $server->name: " . $e->getMessage());
            Log::error("Sync failed for server $id: " . $e->getMessage());
        }
    }

    // Add this new method
    public function getListeners()
    {
        return array_merge($this->listeners, [
            'echo:sync.completed,SyncCompleted' => 'handleSyncCompleted',
            'refreshOnlineCounts' => '$refresh'
        ]);
    }

    public function handleSyncCompleted($event): void
    {
        $this->syncStatus[$event['server_id']] = $event['status'];
        $this->loadServers();
    }
    public function mount(): void
    {
        $this->loadServers();
    }

    public function loadServers(): void
    {
        $this->servers = VpnServer::latest()->get()->map(function ($server) {
            $server->online_user_count = $server->getOnlineUserCount();
            return $server;
        });
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
