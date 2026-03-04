<?php

namespace App\Filament\Pages;

use Filament\Pages\Dashboard as BaseDashboard;

class Dashboard extends BaseDashboard
{
    public function getColumns(): int|array
    {
        return [
            'default' => 1, // phones
            'md'      => 2, // tablets
            'xl'      => 3, // desktop
        ];
    }

    public function getWidgets(): array
    {
        return [
            \App\Filament\Widgets\AdminStats::class,
            \App\Filament\Widgets\ActiveNowUsers::class,
            \App\Filament\Widgets\ConnectionsTrend::class,
            \App\Filament\Widgets\ConnectionsByServer::class,
            \App\Filament\Widgets\ServerStatus::class,
            \App\Filament\Widgets\RecentConnections::class,
        ];
    }
}