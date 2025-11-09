<?php

namespace App\Livewire\Pages\Admin;

use App\Models\VpnServer;
use App\Models\VpnUser;
use App\Models\VpnUserConnection;
use Carbon\Carbon;
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
        $hasProtocol = Schema::hasColumn('vpn_user_connections', 'protocol');

        $select = [
            'vpn_user_id',
            'vpn_server_id',
            'client_ip',
            'virtual_ip',
            'bytes_received',
            'bytes_sent',
            'connected_at',
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
            $resp = \Http::withToken(csrf_token())
                ->post(route('admin.servers.disconnect', $serverId), [
                    'username' => $username,
                ]);

            $this->dispatchBrowserEvent('notify', [
                'type'    => $resp->successful() ? 'success' : 'error',
                'message' => $resp->successful()
                    ? "Disconnected {$username} from server #{$serverId}"
                    : "Failed to disconnect {$username}",
            ]);
        } catch (\Throwable $e) {
            $this->dispatchBrowserEvent('notify', [
                'type'    => 'error',
                'message' => "Error disconnecting {$username}: " . $e->getMessage(),
            ]);
        }

        // Livewire will re-render after action
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
            'serverMeta'             => $serverMeta,
            'seedUsersByServer'      => $usersByServer,
            'seedTotals'             => $seedTotals,
        ]);
    }

    /* --------------------------- Helper methods -------------------------- */

    /**
     * Returns:
     *  - serverMeta: id => ['name' => string]
     *  - usersByServer: id => [ { username, client_ip, virtual_ip, protocol, ... } ]
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

        $hasProtocol = Schema::hasColumn('vpn_user_connections', 'protocol');

        $select = [
            'vpn_user_id',
            'vpn_server_id',
            'client_ip',
            'virtual_ip',
            'bytes_received',
            'bytes_sent',
            'connected_at',
        ];
        if ($hasProtocol) {
            $select[] = 'protocol';
        }

        $rows = VpnUserConnection::query()
            ->where('is_connected', true)
            ->with('vpnUser:id,username')
            ->select($select)
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
                'protocol'        => $hasProtocol
                    ? ($r->protocol ?: 'openvpn')
                    : 'openvpn',
                'connected_human' => $at?->diffForHumans(),
                'connected_fmt'   => $at?->toIso8601String(),
                'formatted_bytes' => null,
                'down_mb'         => $r->bytes_received
                    ? round($r->bytes_received / 1048576, 2)
                    : 0.0,
                'up_mb'           => $r->bytes_sent
                    ? round($r->bytes_sent / 1048576, 2)
                    : 0.0,
            ];
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