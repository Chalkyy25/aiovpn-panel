<?php

namespace App\Filament\Widgets;

use App\Models\VpnConnection;
use App\Models\VpnServer;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

class AdminStats extends BaseWidget
{
    // WireGuard needs longer to avoid false “offline”
    private const OPENVPN_STALE_SECONDS = 120;
    private const WIREGUARD_STALE_SECONDS = 300;

    protected static ?string $pollingInterval = '15s';

    protected function getStats(): array
    {
        $now = now();

        $serversTotal = VpnServer::count();

        // “online” by your server heartbeat
        $serversOnline = VpnServer::where('is_online', 1)->count();

        // Live connections: active + not stale (per protocol)
        $activeLive = VpnConnection::query()
            ->where('is_active', 1)
            ->whereNotNull('last_seen_at')
            ->where(function ($q) use ($now) {
                $q->where(function ($q) use ($now) {
                    $q->where('protocol', 'OPENVPN')
                      ->where('last_seen_at', '>=', $now->copy()->subSeconds(self::OPENVPN_STALE_SECONDS));
                })->orWhere(function ($q) use ($now) {
                    $q->where('protocol', 'WIREGUARD')
                      ->where('last_seen_at', '>=', $now->copy()->subSeconds(self::WIREGUARD_STALE_SECONDS));
                });
            })
            ->count();

        $usersOnline = VpnConnection::query()
            ->where('is_active', 1)
            ->whereNotNull('last_seen_at')
            ->where(function ($q) use ($now) {
                $q->where(function ($q) use ($now) {
                    $q->where('protocol', 'OPENVPN')
                      ->where('last_seen_at', '>=', $now->copy()->subSeconds(self::OPENVPN_STALE_SECONDS));
                })->orWhere(function ($q) use ($now) {
                    $q->where('protocol', 'WIREGUARD')
                      ->where('last_seen_at', '>=', $now->copy()->subSeconds(self::WIREGUARD_STALE_SECONDS));
                });
            })
            ->distinct('vpn_user_id')
            ->count('vpn_user_id');

        // “stale actives” = marked active but not seen recently
        $staleActives = VpnConnection::query()
            ->where('is_active', 1)
            ->whereNotNull('last_seen_at')
            ->where(function ($q) use ($now) {
                $q->where(function ($q) use ($now) {
                    $q->where('protocol', 'OPENVPN')
                      ->where('last_seen_at', '<', $now->copy()->subSeconds(self::OPENVPN_STALE_SECONDS));
                })->orWhere(function ($q) use ($now) {
                    $q->where('protocol', 'WIREGUARD')
                      ->where('last_seen_at', '<', $now->copy()->subSeconds(self::WIREGUARD_STALE_SECONDS));
                });
            })
            ->count();

        return [
            Stat::make('Total Servers', $serversTotal)->color('gray'),
            Stat::make('Online Servers', $serversOnline)->color($serversOnline === $serversTotal ? 'success' : 'warning'),
            Stat::make('Live Connections', $activeLive)->color('success'),
            Stat::make('Users Online', $usersOnline)->color('success'),
            Stat::make('Stale Actives', $staleActives)->color($staleActives > 0 ? 'danger' : 'gray')
                ->description('Active in DB but not seen recently'),
        ];
    }
}