<?php

namespace App\Livewire\Pages\Admin;

use App\Models\VpnServer;
use App\Models\VpnUser;
use App\Models\VpnUserConnection;
use Carbon\Carbon;
use Illuminate\Contracts\View\View as ViewContract;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class VpnDashboard extends Component
{
    /** UI state */
    public ?int $selectedServerId = null;
    public bool $showAllServers = true;

    /** Optional UI knobs you already had */
    public int $hoursPerDay = 3;
    public array $liveLogs = [];
    public int $maxLogs = 100;

    /* ----------------------------- Lifecycle ----------------------------- */

    public function mount(): void
    {
        $this->showAllServers = true;
    }

    /* -------------------------- UI interactions -------------------------- */

    public function selectServer(?int $serverId = null): void
    {
        $this->selectedServerId = $serverId;
        $this->showAllServers = $serverId === null;
    }

    /* --------------------------- Computed props -------------------------- */

    /** Servers that finished deploying + their active connection count */
    public function getServersProperty()
    {
        return VpnServer::query()
            ->where('deployment_status', 'succeeded')
            ->withCount('activeConnections')
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /** Table rows for the “Active Connections” section */
    public function getActiveConnectionsProperty()
    {
        $q = VpnUserConnection::query()
            ->where('is_connected', true)
            ->with([
                'vpnUser:id,username',
                'vpnServer:id,name',
            ])
            ->select([
                'vpn_user_id',
                'vpn_server_id',
                'client_ip',
                'virtual_ip',
                'bytes_received',
                'bytes_sent',
                'connected_at',
            ]);

        if (!$this->showAllServers && $this->selectedServerId) {
            $q->where('vpn_server_id', $this->selectedServerId);
        }

        return $q->orderByDesc('connected_at')->get();
    }

    public function getTotalOnlineUsersProperty(): int
    {
        return (int) VpnUser::query()->where('is_online', true)->count();
    }

    public function getTotalActiveConnectionsProperty(): int
    {
        return (int) VpnUserConnection::query()->where('is_connected', true)->count();
    }

    public function getRecentlyDisconnectedProperty()
    {
        return VpnUserConnection::query()
            ->where('is_connected', false)
            ->whereNotNull('disconnected_at')
            ->with([
                'vpnUser:id,username',
                'vpnServer:id,name',
            ])
            ->orderByDesc('disconnected_at')
            ->limit(10)
            ->get([
                'vpn_user_id',
                'vpn_server_id',
                'client_ip',
                'disconnected_at',
                'bytes_received',
                'bytes_sent',
                'connected_at',
            ]);
    }

    /* -------------------------- Livewire endpoints ----------------------- */

    /**
     * Polled by Alpine every 5s.
     * Returns the fleet snapshot your JS expects:
     *  - usersByServer: { [serverId]: [{ username, client_ip, virtual_ip, connected_human, connected_fmt, down_mb, up_mb }] }
     *  - totals: { online_users, active_connections, active_servers }
     */
    public function getLiveStats(): array
    {
        [$serverMeta, $usersByServer] = $this->buildSnapshot();
        $totals = $this->computeTotals($serverMeta, $usersByServer);

        return [
            'usersByServer' => $usersByServer,
            'totals'        => $totals,
        ];
    }
    
    public function disconnectUser(int $serverId, string $username): void
{
    try {
        $resp = \Http::withToken(csrf_token()) // not really needed if same app, but safe
            ->post(route('admin.servers.disconnect', $serverId), [
                'username' => $username,
            ]);

        if ($resp->successful()) {
            $this->dispatchBrowserEvent('notify', [
                'type' => 'success',
                'message' => "Disconnected {$username} from server #{$serverId}"
            ]);
        } else {
            $this->dispatchBrowserEvent('notify', [
                'type' => 'error',
                'message' => "Failed to disconnect {$username}"
            ]);
        }
    } catch (\Throwable $e) {
        $this->dispatchBrowserEvent('notify', [
            'type' => 'error',
            'message' => "Error disconnecting {$username}: " . $e->getMessage()
        ]);
    }

    // Refresh snapshot so table updates
    $this->render();
}

    /* ------------------------------- Render ------------------------------ */

    public function render(): ViewContract
    {
        // Build initial snapshot so the page has data before Echo/polling kicks in
        [$serverMeta, $usersByServer] = $this->buildSnapshot();
        $seedTotals = $this->computeTotals($serverMeta, $usersByServer);

        return view('livewire.pages.admin.vpn-dashboard', [
            // server list & cards
            'servers'                => $this->servers,
            'activeConnections'      => $this->activeConnections,
            'totalOnlineUsers'       => $this->totalOnlineUsers,
            'totalActiveConnections' => $this->totalActiveConnections,
            'recentlyDisconnected'   => $this->recentlyDisconnected,

            // Alpine init() seeds (names match your JS)
            'serverMeta'        => $serverMeta,        // { [id]: { name } }
            'seedUsersByServer' => $usersByServer,     // { [id]: [ rows... ] }
            'seedTotals'        => $seedTotals,        // { online_users, active_connections, active_servers }
        ]);
    }

    /* --------------------------- Helper methods -------------------------- */

    /**
     * Returns:
     *  - serverMeta: id => ['name' => string]
     *  - usersByServer: id => [ { username, client_ip, virtual_ip, connected_human, connected_fmt, down_mb, up_mb } ]
     */
    private function buildSnapshot(): array
    {
        // Servers (only deployed ones)
        $servers = VpnServer::query()
            ->where('deployment_status', 'succeeded')
            ->orderBy('id')
            ->get(['id', 'name']);

        $serverMeta = [];
        foreach ($servers as $s) {
            $serverMeta[(string) $s->id] = ['name' => $s->name];
        }

        // Current live connections (whole fleet)
        $rows = VpnUserConnection::query()
            ->where('is_connected', true)
            ->with([
                'vpnUser:id,username',
            ])
            ->select([
                'vpn_user_id',
                'vpn_server_id',
                'client_ip',
                'virtual_ip',
                'bytes_received',
                'bytes_sent',
                'connected_at',
            ])
            ->get();

        $usersByServer = [];

        foreach ($rows as $r) {
            $sid   = (string) $r->vpn_server_id;
            $uname = $r->vpnUser?->username ?? 'unknown';
            $at    = $r->connected_at ? Carbon::parse($r->connected_at) : null;

            $usersByServer[$sid] ??= [];

            $usersByServer[$sid][] = [
                'username'        => $uname,
                'client_ip'       => $r->client_ip,
                'virtual_ip'      => $r->virtual_ip,
                'connected_human' => $at?->diffForHumans(),
                'connected_fmt'   => $at?->toIso8601String(),
                'formatted_bytes' => null, // let the UI format aggregate if you want
                'down_mb'         => $r->bytes_received ? round($r->bytes_received / 1048576, 2) : 0.0,
                'up_mb'           => $r->bytes_sent     ? round($r->bytes_sent     / 1048576, 2) : 0.0,
            ];
        }

        return [$serverMeta, $usersByServer];
    }

    /** Compute KPIs for the dashboard header */
    private function computeTotals(array $serverMeta, array $usersByServer): array
    {
        $uniqueUsers = [];
        $activeConnections = 0;
        $activeServers = 0;

        foreach ($serverMeta as $sid => $_) {
            $list = $usersByServer[$sid] ?? [];
            if (!empty($list)) {
                $activeServers++;
            }
            foreach ($list as $u) {
                if (!empty($u['username'])) {
                    $uniqueUsers[$u['username']] = true;
                }
            }
            $activeConnections += count($list);
        }

        // If no servers currently have users, show total number of deployed servers as “active servers”
        if ($activeServers === 0) {
            $activeServers = count($serverMeta);
        }

        return [
            'online_users'       => count($uniqueUsers),
            'active_connections' => $activeConnections,
            'active_servers'     => $activeServers,
        ];
    }
}