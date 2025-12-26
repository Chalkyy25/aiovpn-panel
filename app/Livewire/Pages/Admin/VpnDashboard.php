<?php

namespace App\Livewire\Pages\Admin;

use App\Models\VpnServer;
use App\Models\VpnUser;
use App\Models\VpnUserConnection;
use Carbon\Carbon;
use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\Support\Facades\DB;
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
        // This is the old table view data (fine to keep)
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

        if (!$hasProtocol) {
            // if protocol column doesn't exist, leave it out
            $select = array_values(array_filter($select, fn ($c) => $c !== 'protocol'));
        } else {
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

    public function disconnectUser(int $serverId, int $connectionId, string $username): void
    {
        try {
            $server = VpnServer::findOrFail($serverId);
            $connection = VpnUserConnection::findOrFail($connectionId);

            $controller = app(\App\Http\Controllers\VpnDisconnectController::class);
            $request = request()->merge([
                'client_id'    => $connection->client_id,
                'username'     => $username,
                'session_key'  => $connection->session_key,
                'protocol'     => $connection->protocol,
                'public_key'   => $connection->public_key,
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
            'serverMeta'             => $serverMeta,
            'seedUsersByServer'      => $usersByServer,
            'seedTotals'             => $seedTotals,
        ]);
    }

    /* --------------------------- Helper methods -------------------------- */

    /**
     * Snapshot = TRUTH ONLY:
     * - only rows in vpn_user_connections where is_connected=1
     * - grouped by vpn_server_id
     * - username joined from vpn_users
     *
     * Returns:
     *  - serverMeta: id => ['name' => string]
     *  - usersByServer: id => [ rows... ]
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

        $serverIds = array_map('intval', array_keys($serverMeta));

        $hasProtocol = Schema::hasColumn('vpn_user_connections', 'protocol');
        $hasSeenAt   = Schema::hasColumn('vpn_user_connections', 'seen_at');

        // Pull ONLY active connections (truth)
        $rows = DB::table('vpn_user_connections as vuc')
            ->join('vpn_users as vu', 'vu.id', '=', 'vuc.vpn_user_id')
            ->whereIn('vuc.vpn_server_id', $serverIds)
            ->where('vuc.is_connected', 1)
            ->select([
                'vuc.id',
                'vuc.vpn_server_id',
                'vu.username',
                'vuc.client_ip',
                'vuc.virtual_ip',
                'vuc.connected_at',
                'vuc.updated_at',
                'vuc.bytes_received',
                'vuc.bytes_sent',
                'vuc.session_key',
                'vuc.client_id',
                'vuc.public_key',
                $hasProtocol ? 'vuc.protocol' : DB::raw("NULL as protocol"),
                $hasSeenAt ? 'vuc.seen_at' : DB::raw("NULL as seen_at"),
            ])
            ->orderByDesc($hasSeenAt ? 'vuc.seen_at' : 'vuc.updated_at')
            ->orderByDesc('vuc.connected_at')
            ->get();

        foreach ($rows as $r) {
            $sid = (string) $r->vpn_server_id;
            if (!isset($usersByServer[$sid])) {
                // should not happen, but never leak into wrong buckets
                $usersByServer[$sid] = [];
            }

            $proto = $hasProtocol && $r->protocol !== null && $r->protocol !== ''
                ? strtolower((string) $r->protocol)
                : 'openvpn';

            // pick "last seen" best-effort
            $seenAt = $r->seen_at ?: $r->updated_at ?: $r->connected_at;
            $seen = $seenAt ? Carbon::parse($seenAt) : null;

            // Stable, per-row unique IDs for frontend
            // - OpenVPN: prefer client_id if present, else DB row id
            // - WireGuard: session_key usually contains wg:... already; still keep ids
            $connectionId = $r->client_id ?? (int) $r->id;

            $usersByServer[$sid][] = [
                'connection_id'   => (int) $connectionId,
                'session_key'     => $r->session_key ?: null,
                'username'        => $r->username ?: 'unknown',
                'server_name'     => $serverMeta[$sid]['name'] ?? "Server {$sid}",
                'client_ip'       => $r->client_ip,
                'virtual_ip'      => $r->virtual_ip,
                'protocol'        => $proto,
                'connected_at'    => $r->connected_at ? Carbon::parse($r->connected_at)->toIso8601String() : null,
                'connected_human' => $seen ? $seen->diffForHumans() : '—',
                'seen_at'         => $seen ? $seen->toIso8601String() : null,
                'bytes_in'        => (int) ($r->bytes_received ?? 0),
                'bytes_out'       => (int) ($r->bytes_sent ?? 0),
                'is_connected'    => true,
                'formatted_bytes' => null,
                'down_mb'         => $r->bytes_received ? round($r->bytes_received / 1048576, 2) : 0.0,
                'up_mb'           => $r->bytes_sent ? round($r->bytes_sent / 1048576, 2) : 0.0,
                'public_key'      => $r->public_key ?? null,
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

            // all rows in snapshot are active; still keep the guard
            $onlineList = array_values(array_filter($list, fn ($u) => (bool)($u['is_connected'] ?? false)));

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