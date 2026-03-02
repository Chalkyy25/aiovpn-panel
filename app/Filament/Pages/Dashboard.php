<?php

namespace App\Filament\Pages;

use Filament\Pages\Dashboard as BaseDashboard;

class Dashboard extends BaseDashboard
{
    public function getColumns(): int|array
    {
        // Force 2-column grid on desktop
        return 2;
    }

    public function getWidgets(): array
    {
        // Explicit order (don’t rely on provider order)
        return [
            \App\Filament\Widgets\AdminStats::class,
            \App\Filament\Widgets\ConnectionsTrend::class,
            \App\Filament\Widgets\ConnectionsByServer::class,
            \App\Filament\Widgets\ServerStatus::class,
            \App\Filament\Widgets\RecentConnections::class,
        ];
    }
}
