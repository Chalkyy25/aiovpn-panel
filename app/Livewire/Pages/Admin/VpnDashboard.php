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
use Illuminate\Support\Facades\Log;
use App\Services\OpenVpnStatusParser;
use phpseclib3\Net\SSH2;

#[Layout('layouts.app')]
class VpnDashboard extends Component
{
    public $selectedServerId = null;
    public $showAllServers = true;
    public int $hoursPerDay = 3;
    public $liveLogs = [];
    public $maxLogs = 100;

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
    /** @var VpnUserConnection $connection */
    $connection = VpnUserConnection::with(['vpnUser','vpnServer'])->findOrFail($connectionId);

    $server   = $connection->vpnServer;
    $username = $connection->vpnUser?->username;

    if (!$server || !$username) {
        session()->flash('message', 'Missing server or username.');
        return;
    }

    // 1) Kick via OpenVPN management (remote)
    try {
        // Build remote "client-kill USERNAME" piped to nc on mgmt port 7505
        $mgmtPort = 7505; // matches your deploy script
        $remoteCmd = "bash -lc " . escapeshellarg(
            "printf 'client-kill " . addcslashes($username, "'\"\\") . "\\nquit\\n' | nc -w 3 127.0.0.1 {$mgmtPort} || true"
        );

        $ssh = $server->getSshCommand(); // from your model
        $full = $ssh . ' ' . escapeshellarg($remoteCmd);

        $out = [];
        $rc  = 0;
        exec($full, $out, $rc);
        Log::info("🔌 Disconnect cmd sent to {$server->name} for {$username} (rc={$rc})", ['out' => $out]);
        // We don't hard-fail on rc != 0; OpenVPN may already have dropped the client.
    } catch (\Throwable $e) {
        Log::warning("⚠️ Disconnect management call failed: ".$e->getMessage());
    }

    // 2) Update DB state so UI reflects immediately
    if ($connection->is_connected) {
        $connection->update([
            'is_connected'    => false,
            'disconnected_at' => now(),
        ]);

        VpnUserConnection::updateUserOnlineStatusIfNoActiveConnections($connection->vpn_user_id);
    }

    session()->flash('message', "User {$username} has been disconnected from {$server->name}");
}
    
    public function getLiveClientsProperty(): array
{
    if (!$this->selectedServerId) return [];

    $server = VpnServer::find($this->selectedServerId);

    if (!$server || !$server->ip_address || !$server->ssh_user || !$server->ssh_password) {
        return [];
    }

    try {
        $ssh = new SSH2($server->ip_address);
        if (!$ssh->login($server->ssh_user, $server->ssh_password)) {
            return [];
        }

        $raw = OpenVpnStatusParser::fetchAnyStatus($ssh);
        $parsed = OpenVpnStatusParser::parse($raw);

        return $parsed['clients'] ?? [];
    } catch (\Throwable $e) {
        return [];
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
