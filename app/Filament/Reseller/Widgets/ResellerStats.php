<?php

namespace App\Filament\Reseller\Widgets;

use App\Models\VpnConnection;
use App\Models\VpnUser;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ResellerStats extends BaseWidget
{
    protected static ?int $sort = 1;

    protected int | string | array $columnSpan = [
        'default' => 'full',
        'xl' => 3,
    ];

    protected function getStats(): array
    {
        $resellerId = auth()->id();
        $now = now();

        $myUsers = VpnUser::query()->where('client_id', $resellerId)->count();

        $liveConnections = VpnConnection::query()
            ->live($now)
            ->whereHas('vpnUser', fn ($q) => $q->where('client_id', $resellerId))
            ->count();

        $usersOnline = VpnConnection::query()
            ->live($now)
            ->whereHas('vpnUser', fn ($q) => $q->where('client_id', $resellerId))
            ->distinct('vpn_user_id')
            ->count('vpn_user_id');

        $expiringSoon = VpnUser::query()
            ->where('client_id', $resellerId)
            ->whereNotNull('expires_at')
            ->whereBetween('expires_at', [$now, $now->copy()->addDays(7)])
            ->count();

        return [
            Stat::make('My VPN Users', $myUsers)->color('primary'),
            Stat::make('Users Online', $usersOnline)->color('success'),
            Stat::make('Live Connections', $liveConnections)->color('success'),
            Stat::make('Expiring (7d)', $expiringSoon)->color($expiringSoon > 0 ? 'warning' : 'gray'),
        ];
    }
}
