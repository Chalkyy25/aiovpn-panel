<?php

namespace App\Filament\Pages;

use Filament\Pages\Dashboard as BaseDashboard;

class Dashboard extends BaseDashboard
{
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

            // Top stats row
            \App\Filament\Widgets\AdminStats::class,

            // Main charts + activity
            \App\Filament\Widgets\ConnectionsTrend::class,
            \App\Filament\Widgets\TopServers::class,
            \App\Filament\Widgets\RealtimeConnectionFeed::class,

            // User activity
            \App\Filament\Widgets\ActiveNowUsers::class,
            \App\Filament\Widgets\RecentConnections::class,

            // Infrastructure
            \App\Filament\Widgets\ServerStatus::class,
            \App\Filament\Widgets\ConnectionsByServer::class,

            // Business / retention
            \App\Filament\Widgets\ExpiringSoonUsers::class,

            // Quick actions
            \App\Filament\Widgets\DashboardLinks::class,
        ];
    }
}