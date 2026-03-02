<?php

namespace App\Filament\Reseller\Widgets;

use App\Models\VpnUser;
use App\Models\VpnUserConnection;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ResellerStats extends BaseWidget
{
    protected function getStats(): array
    {
        $resellerId = auth()->id();

        return [
            Stat::make(
                'My VPN Users',
                VpnUser::where('reseller_id', $resellerId)->count()
            )->color('primary'),

            Stat::make(
                'Users Online',
                VpnUserConnection::where('is_connected', true)
                    ->whereHas('vpnUser', fn ($q) =>
                        $q->where('reseller_id', $resellerId)
                    )->count()
            )->color('success'),

            Stat::make(
                'Active Sessions',
                VpnUserConnection::where('is_connected', true)
                    ->whereHas('vpnUser', fn ($q) =>
                        $q->where('reseller_id', $resellerId)
                    )->count()
            )->color('warning'),
        ];
    }
}
