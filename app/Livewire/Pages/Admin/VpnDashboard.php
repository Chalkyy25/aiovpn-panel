<?php

namespace App\Livewire\Pages\Admin;

use App\Models\VpnServer;
use App\Models\VpnUser;
use App\Models\VpnUserConnection;
use App\Services\MgmtSnapshotStore;
use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class VpnDashboard extends Component
{
    public ?int $selectedServerId = null;
    public bool $showAllServers = true;

    public int $hoursPerDay = 3;
    public array $liveLogs = [];
    public int $maxLogs = 100;

    public function mount(): void
    {
        $this->showAllServers = true;
    }

    public function selectServer(?int $serverId = null): void
    {
        $this->selectedServerId = $serverId;
        $this->showAllServers = ($serverId === null);
    }

    /* --------------------------- Computed props -------------------------- */

    public function getServersProperty()
    {
        return VpnServer::query()
            ->whereIn('deployment_status', ['succeeded', 'deployed'])
            ->withCount('activeConnections')
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    public function getActiveConnectionsProperty()
    {
        // Legacy table view data (DB truth). Keep this DB-based.
        $hasProtocol = Schema::hasColumn('vpn_user_connections', 'protocol');

        $select = [
            'id',
            'vpn_user_id',
            'vpn_server_id',
            'client_ip',
            'virtual_ip',
            'bytes_received',
            'bytes_sent',
            'connected_at',
            'seen_at',
            'session_key',
            'client_id',
            'public_key',
        ];

        if ($hasProtocol) {
            $select[] = 'protocol';
        }

        $q = VpnUserConnection::query()
            ->where('is_connected', true)
            ->with([
                'vpnUser:id,username',
                'vpnServer:id,name',
            ])
            ->select($select);

        if (!$this->showAllServers && $this->selectedServerId) {
            $q->where('vpn_server_id', $this->selectedServerId);
        }

        return $q->orderByDesc('seen_at')->orderByDesc('connected_at')->get();
    }

    public function getTotalOnlineUsersProperty(): int
    {
        return (int) VpnUser::query()
            ->where('is_online', true)
            ->count();
    }

    public function getTotalActiveConnectionsProperty(): int
    {
        return (int) VpnUserConnection::query()
            ->where('is_connected', true)
            ->count();
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
     * Poll endpoint used by Alpine.
     * This now pulls from Redis snapshots (single source of truth for dashboard tiles + lists).
     */
    public function getLiveStats(): array
{
    $this->skipRender(); // prevents DOM morphing / Alpine reset

    [$serverMeta, $usersByServer] = $this->buildSnapshot();
    $totals = $this->computeTotals($serverMeta, $usersByServer);

    return [
        'serverMeta'    => $serverMeta,
        'usersByServer' => $usersByServer,
        'totals'        => $totals,
        'ts'            => now()->toIso8601String(),
    ];
}

    public function disconnectUser(int $serverId, int $connectionId, string $username): void
    {
        try {
            $server = VpnServer::findOrFail($serverId);
            $connection = VpnUserConnection::findOrFail($connectionId);

            $controller = app(\App\Http\Controllers\VpnDisconnectController::class);
            $request = request()->merge([
                'client_id'   => $connection->client_id,
                'username'    => $username,
                'session_key' => $connection->session_key,
                'protocol'    => $connection->protocol,
                'public_key'  => $connection->public_key,
            ]);

            $result = $controller->disconnect($request, $server);
            $data = $result->getData(true);

            $this->dispatch('notify', [
                'type'    => ($data['ok'] ?? false) ? 'success' : 'error',
                'message' => $data['message'] ?? "Attempted to disconnect {$username}",
            ]);
        } catch (\Throwable $e) {
            $this->dispatch('notify', [
                'type'    => 'error',
                'message' => "Error disconnecting {$username}: " . $e->getMessage(),
            ]);
        }
    }

    /* ------------------------------- Render ------------------------------ */

    public function render(): ViewContract
    {
        [$serverMeta, $usersByServer] = $this->buildSnapshot();
        $seedTotals = $this->computeTotals($serverMeta, $usersByServer);

        return view('livewire.pages.admin.vpn-dashboard', [
            'servers'                => $this->servers,
            'activeConnections'      => $this->activeConnections,
            'totalOnlineUsers'       => $this->totalOnlineUsers,
            'totalActiveConnections' => $this->totalActiveConnections,
            'recentlyDisconnected'   => $this->recentlyDisconnected,

            // Alpine seeds
            'serverMeta'        => $serverMeta,
            'seedUsersByServer' => $usersByServer,
            'seedTotals'        => $seedTotals,
        ])->layoutData([
            'heading'    => 'VPN Monitor',
            'subheading' => 'Live overview of users, servers & connections',
        ]);
    }

    /* --------------------------- Helper methods -------------------------- */

    /**
     * Dashboard snapshot source of truth:
     * - serverMeta from DB (names + ids)
     * - usersByServer from Redis snapshots (MgmtSnapshotStore)
     *
     * Returns:
     *  - serverMeta: [id => ['name' => string]]
     *  - usersByServer: [id => [rows...]]
     */
    private function buildSnapshot(): array
    {
        $servers = VpnServer::query()
            ->whereIn('deployment_status', ['succeeded', 'deployed'])
            ->orderBy('id')
            ->get(['id', 'name']);

        $serverMeta = [];
        foreach ($servers as $s) {
            $serverMeta[(string) $s->id] = ['name' => $s->name];
        }

        // Always provide explicit keys for Alpine
        $usersByServer = [];
        foreach ($serverMeta as $sid => $_) {
            $usersByServer[$sid] = [];
        }

        if (empty($serverMeta)) {
            return [$serverMeta, $usersByServer];
        }

        /** @var \App\Services\MgmtSnapshotStore $store */
        $store = app(MgmtSnapshotStore::class);

        // Pull snapshot from Redis (single source of truth for dashboard list/tiles)
        foreach (array_keys($serverMeta) as $sid) {
            $snap = $store->get((int) $sid);

            // Expected shape: ['ts' => ..., 'users' => [...]]
            $usersByServer[$sid] = $snap['users'] ?? [];
        }

        return [$serverMeta, $usersByServer];
    }

    private function computeTotals(array $serverMeta, array $usersByServer): array
    {
        $uniqueUsers = [];
        $activeConnections = 0;
        $activeServers = 0;

        foreach ($serverMeta as $sid => $_) {
            $list = $usersByServer[$sid] ?? [];

            // Treat missing is_connected as true (snapshot rows are "active")
            $onlineList = array_values(array_filter(
                $list,
                fn ($u) => ($u['is_connected'] ?? true) === true
            ));

            if (!empty($onlineList)) {
                $activeServers++;
            }

            foreach ($onlineList as $u) {
                if (!empty($u['username'])) {
                    $uniqueUsers[$u['username']] = true;
                }
            }

            $activeConnections += count($onlineList);
        }

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