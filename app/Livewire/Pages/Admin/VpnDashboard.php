<?php

namespace App\Livewire\Pages\Admin;

use App\Models\VpnServer;
use App\Models\VpnUser;
use App\Models\VpnUserConnection;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class VpnDashboard extends Component
{
    public $selectedServerId = null;
    public $showAllServers = true;

    public function mount()
    {
        // Default to showing all servers
        $this->showAllServers = true;
    }

    public function selectServer($serverId = null)
    {
        $this->selectedServerId = $serverId;
        $this->showAllServers = $serverId === null;
    }

    public function getServersProperty()
    {
        return VpnServer::where('deployment_status', 'succeeded')
            ->withCount(['activeConnections'])
            ->orderBy('name')
            ->get();
    }

    public function getActiveConnectionsProperty()
    {
        $query = VpnUserConnection::with(['vpnUser', 'vpnServer'])
            ->where('is_connected', true);

        if (!$this->showAllServers && $this->selectedServerId) {
            $query->where('vpn_server_id', $this->selectedServerId);
        }

        return $query->orderBy('connected_at', 'desc')->get();
    }

    public function getTotalOnlineUsersProperty()
    {
        return VpnUser::where('is_online', true)->count();
    }

    public function getTotalActiveConnectionsProperty()
    {
        return VpnUserConnection::where('is_connected', true)->count();
    }

    public function getRecentlyDisconnectedProperty()
    {
        return VpnUserConnection::with(['vpnUser', 'vpnServer'])
            ->where('is_connected', false)
            ->whereNotNull('disconnected_at')
            ->orderBy('disconnected_at', 'desc')
            ->limit(10)
            ->get();
    }

    public function disconnectUser($connectionId)
    {
        $connection = VpnUserConnection::findOrFail($connectionId);

        if ($connection->is_connected) {
            $connection->update([
                'is_connected' => false,
                'disconnected_at' => now(),
            ]);

            // Check if user has any other active connections
            $hasActiveConnections = VpnUserConnection::where('vpn_user_id', $connection->vpn_user_id)
                ->where('is_connected', true)
                ->exists();

            if (!$hasActiveConnections) {
                $connection->vpnUser->update([
                    'is_online' => false,
                    'last_seen_at' => now(),
                ]);
            }

            session()->flash('message', "User {$connection->vpnUser->username} has been disconnected from {$connection->vpnServer->name}");
        }
    }

    public function render(): Factory|Application|View|\Illuminate\View\View|\Illuminate\Contracts\Foundation\Application
    {
        return view('livewire.pages.admin.vpn-dashboard', [
            'servers' => $this->servers,
            'activeConnections' => $this->activeConnections,
            'totalOnlineUsers' => $this->totalOnlineUsers,
            'totalActiveConnections' => $this->totalActiveConnections,
            'recentlyDisconnected' => $this->recentlyDisconnected,
        ]);
    }
}
