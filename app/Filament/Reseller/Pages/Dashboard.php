<?php

namespace App\Filament\Reseller\Pages;

use App\Filament\Reseller\Widgets\ActiveNowUsers;
use App\Filament\Reseller\Widgets\ConnectionsByServer;
use App\Filament\Reseller\Widgets\ConnectionsTrend;
use App\Filament\Reseller\Widgets\DashboardLinks;
use App\Filament\Reseller\Widgets\ExpiringSoonUsers;
use App\Filament\Reseller\Widgets\RecentConnections;
use App\Filament\Reseller\Widgets\ResellerStats;
use App\Filament\Reseller\Widgets\ServerStatus;
use Filament\Pages\Dashboard as BaseDashboard;

class Dashboard extends BaseDashboard
{
    protected static ?string $navigationIcon = 'heroicon-o-home';

    protected static ?int $navigationSort = 1;

    public function getColumns(): int|array
    {
        return [
            'default' => 1,
            'md'      => 2,
            'xl'      => 3,
        ];
    }

    public function getWidgets(): array
    {
        return [
            ResellerStats::class,
            ConnectionsTrend::class,
            ActiveNowUsers::class,
            DashboardLinks::class,
            ExpiringSoonUsers::class,
            ConnectionsByServer::class,
            RecentConnections::class,
            ServerStatus::class,
        ];
    }
}
