<?php

namespace App\Livewire\Pages\Admin;

use App\Models\VpnServer;
use App\Models\VpnUser;
use App\Models\VpnUserConnection;
use App\Models\WireguardPeer;
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
            'id',
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

    public function disconnectUser(int $serverId, int $connectionId, string $username): void
    {
        try {
            $server = VpnServer::findOrFail($serverId);
            $connection = VpnUserConnection::findOrFail($connectionId);
            
            // Call the disconnect controller logic
            $controller = app(\App\Http\Controllers\VpnDisconnectController::class);
            $request = request()->merge([
                'client_id' => $connection->client_id,
                'username' => $username,
                'session_key' => $connection->session_key,
                'protocol' => $connection->protocol,
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
        $hasSeenAt = Schema::hasColumn('vpn_user_connections', 'seen_at');

        $select = [
            'id',
            'vpn_user_id',
            'vpn_server_id',
            'session_key',
            'client_ip',
            'virtual_ip',
            'bytes_received',
            'bytes_sent',
            'connected_at',
            'updated_at',
        ];
        
        if ($hasProtocol) {
            $select[] = 'protocol';
        }
        if ($hasSeenAt) {
            $select[] = 'seen_at';
        }

        // OpenVPN snapshot: only active sessions
        $rows = VpnUserConnection::query()
            ->where('is_connected', true)
            ->when($hasProtocol, function ($q) {
                // Never mix OpenVPN and WireGuard here.
                $q->where(function ($qq) {
                    $qq->whereNull('protocol')->orWhere('protocol', '!=', 'WIREGUARD');
                });
            })
            ->with('vpnUser:id,username')
            ->select($select)
            ->get();

        // IMPORTANT: snapshot must be explicit for every server.
        // Missing keys are ambiguous (partial response) and can cause UI decay.
        $usersByServer = [];
        foreach ($serverMeta as $sid => $_meta) {
            $usersByServer[$sid] = [];
        }

        foreach ($rows as $r) {
            $sid   = (string) $r->vpn_server_id;
            $uname = $r->vpnUser?->username ?? 'unknown';
            $at    = $r->connected_at ? Carbon::parse($r->connected_at) : null;
            $seenAt = ($hasSeenAt && $r->seen_at) ? Carbon::parse($r->seen_at) : ($r->updated_at ? Carbon::parse($r->updated_at) : null);

            // $usersByServer already has all server keys; keep defensive fallback.
            $usersByServer[$sid] ??= [];

            $usersByServer[$sid][] = [
                'connection_id'   => $r->id,
                'session_key'     => $r->session_key,
                'username'        => $uname,
                'server_name'     => $serverMeta[$sid]['name'] ?? "Server {$sid}",
                'client_ip'       => $r->client_ip,
                'virtual_ip'      => $r->virtual_ip,
                'protocol'        => $hasProtocol
                    ? ($r->protocol ?: 'openvpn')
                    : 'openvpn',
                'connected_at'    => $at?->toIso8601String(),
                'connected_human' => $at?->diffForHumans(),
                'seen_at'         => $seenAt?->toIso8601String(),
                'bytes_in'        => (int) ($r->bytes_received ?? 0),
                'bytes_out'       => (int) ($r->bytes_sent ?? 0),
                'is_connected'    => true,
                'formatted_bytes' => null,
                'down_mb'         => $r->bytes_received
                    ? round($r->bytes_received / 1048576, 2)
                    : 0.0,
                'up_mb'           => $r->bytes_sent
                    ? round($r->bytes_sent / 1048576, 2)
                    : 0.0,
            ];
        }

        // WireGuard snapshot: always include configured peers (even if idle)
        $wgPeers = WireguardPeer::with('vpnUser:id,username')
            ->whereIn('vpn_server_id', array_map('intval', array_keys($serverMeta)))
            ->where('revoked', false)
            ->get([
                'vpn_server_id',
                'vpn_user_id',
                'public_key',
                'ip_address',
                'last_handshake_at',
                'transfer_rx_bytes',
                'transfer_tx_bytes',
            ]);

        $wgConnBySession = VpnUserConnection::query()
            ->whereIn('vpn_server_id', array_map('intval', array_keys($serverMeta)))
            ->where('protocol', 'WIREGUARD')
            ->get([
                'id', 'vpn_server_id', 'session_key', 'public_key', 'client_ip', 'virtual_ip',
                'connected_at', 'seen_at', 'bytes_received', 'bytes_sent', 'is_connected',
            ])
            ->keyBy(fn ($r) => (string) $r->vpn_server_id . '|' . (string) $r->session_key);

        $cutoff = now()->subSeconds(180);

        foreach ($wgPeers as $p) {
            $sid = (string) $p->vpn_server_id;
            $sessionKey = 'wg:' . $p->public_key;
            $conn = $wgConnBySession[$sid . '|' . $sessionKey] ?? null;

            $seenAt = $conn?->seen_at ?? $p->last_handshake_at;
            $isOnline = $seenAt ? Carbon::parse($seenAt)->gte($cutoff) : false;

            $usersByServer[$sid][] = [
                'connection_id'   => $conn?->id,
                'session_key'     => $sessionKey,
                'username'        => $p->vpnUser?->username ?? 'unknown',
                'server_name'     => $serverMeta[$sid]['name'] ?? "Server {$sid}",
                'client_ip'       => $conn?->client_ip,
                'virtual_ip'      => $conn?->virtual_ip ?? $p->ip_address,
                'protocol'        => 'wireguard',
                'connected_at'    => optional($conn?->connected_at)?->toIso8601String(),
                'connected_human' => null,
                'seen_at'         => optional($seenAt)?->toIso8601String(),
                'bytes_in'        => (int) ($conn?->bytes_received ?? $p->transfer_rx_bytes),
                'bytes_out'       => (int) ($conn?->bytes_sent ?? $p->transfer_tx_bytes),
                'is_connected'    => $isOnline,
                'formatted_bytes' => null,
                'down_mb'         => 0.0,
                'up_mb'           => 0.0,
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

            $onlineList = array_values(array_filter($list, fn ($u) => (bool)($u['is_connected'] ?? true)));

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